<?php
/**
 * AI product-analysis client.
 *
 * Sends sample product HTML to the shared Dukkan AI endpoint (a Gemini proxy
 * hosted on the portal server) and receives back a scraping profile. The Gemini
 * API key itself never leaves the portal server — every store's plugin calls
 * the same endpoint with a dedicated static token.
 *
 * @package SWI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWI_AI {

	/**
	 * Default endpoint + token. These can be overridden in plugin options
	 * (swi_options) if the endpoint or token ever rotates.
	 *
	 * The endpoint is a Gemini proxy (with reasoning/"thinking" enabled) on the
	 * Dukkan portal server; the Gemini key lives server-side only. The plugin
	 * can also self-host the endpoint via SWI_AI_Server if a store is
	 * designated as its own AI server.
	 */
	const DEFAULT_ENDPOINT = 'https://portal.dukkan.pro/api/ai/analyze-product';
	const DEFAULT_TOKEN    = 'd98b0f6ecde49ab8c7ac2b5371aabbe19887fe4371616038';

	/**
	 * The shared AI endpoint URL.
	 *
	 * @return string
	 */
	public static function endpoint() {
		$endpoint = SWI_Plugin::get_option( 'ai_endpoint', self::DEFAULT_ENDPOINT );
		return '' !== $endpoint ? $endpoint : self::DEFAULT_ENDPOINT;
	}

	/**
	 * The static token authorizing this plugin against the AI endpoint.
	 *
	 * @return string
	 */
	public static function token() {
		$token = SWI_Plugin::get_option( 'ai_token', self::DEFAULT_TOKEN );
		return '' !== $token ? $token : self::DEFAULT_TOKEN;
	}

	/**
	 * The LLM provider to use for analysis/fix: 'gemini' (default) or 'deepseek'.
	 *
	 * @return string
	 */
	public static function provider() {
		$provider = SWI_Plugin::get_option( 'model_provider', 'gemini' );
		return 'deepseek' === $provider ? 'deepseek' : 'gemini';
	}

	/**
	 * Analyze sample product HTML and return a scraping profile.
	 *
	 * @param string $html_simple   Simple product page HTML (may be '').
	 * @param string $html_variable Variable product page HTML (may be '').
	 * @return array|WP_Error Array with 'profile' on success.
	 */
	public static function analyze( $html_simple, $html_variable ) {
		if ( '' === $html_simple && '' === $html_variable ) {
			return new WP_Error( 'swi_ai_empty', __( 'Provide at least one sample product (simple or variable).', 'swi' ) );
		}

		$payload = array();
		if ( '' !== $html_simple ) {
			$payload['html_simple'] = $html_simple;
		}
		if ( '' !== $html_variable ) {
			$payload['html_variable'] = $html_variable;
		}

		return self::request( $payload );
	}

	/**
	 * Analyze with a self-verification loop: generate a profile, actually RUN
	 * it against the sample HTML, and if fields come back empty/wrong, feed the
	 * problems back to the AI to correct itself. Returns the best (or a clean)
	 * profile after a bounded number of attempts.
	 *
	 * @param string $html_simple   Simple product sample HTML (may be '').
	 * @param string $html_variable Variable product sample HTML (may be '').
	 * @param string $base_url      Sample URL (for resolving relative images).
	 * @return array|WP_Error
	 */
	public static function analyze_with_audit( $html_simple, $html_variable, $base_url = '' ) {
		if ( '' === $html_simple && '' === $html_variable ) {
			return new WP_Error( 'swi_ai_empty', __( 'Provide at least one sample product (simple or variable).', 'swi' ) );
		}

		$is_variable = '' !== $html_variable;
		$sample      = $is_variable ? $html_variable : $html_simple;

		$payload = array();
		if ( '' !== $html_simple ) {
			$payload['html_simple'] = $html_simple;
		}
		if ( '' !== $html_variable ) {
			$payload['html_variable'] = $html_variable;
		}

		$best         = null;
		$best_problem = PHP_INT_MAX;
		$feedback     = '';
		$max_attempts = 3; // initial attempt + up to 2 corrections.

		for ( $i = 0; $i < $max_attempts; $i++ ) {
			if ( '' !== $feedback ) {
				$payload['feedback'] = $feedback;
			}

			$profile = self::request( $payload );
			if ( is_wp_error( $profile ) ) {
				return ( null !== $best ) ? $best : $profile;
			}

			$valid = SWI_Profile::validate( $profile );
			if ( is_wp_error( $valid ) ) {
				// Treat a validation error as a fixable problem (feed it back)
				// instead of hard-failing — the model can correct the bad rule.
				$feedback = 'profile validation error: ' . $valid->get_error_message();
				continue;
			}

			// The audit step: execute the profile against the sample and see
			// what it actually extracts.
			$problems = array();
			$data     = SWI_Generic_Scraper::scrape_html( $sample, $base_url, $valid );
			if ( is_wp_error( $data ) ) {
				$problems[] = 'scraper error: ' . $data->get_error_message();
			} else {
				$problems = self::audit_extraction( $data, $is_variable, $sample );
			}

			$score = count( $problems );
			if ( $score < $best_problem ) {
				$best_problem = $score;
				$best         = $valid;
			}

			if ( 0 === $score ) {
				return $valid; // clean profile.
			}

			$feedback = 'extracted problems: ' . implode( '; ', $problems );
		}

		return $best;
	}

	/**
	 * Inspect an extraction result and list the concrete problems, so the AI
	 * can be told exactly which rules to fix.
	 *
	 * @param array  $data            Normalized product data.
	 * @param bool   $expect_variants Whether the sample was a variable product.
	 * @param string $sample_html     Raw sample HTML (for swatch-source signals).
	 * @return array
	 */
	private static function audit_extraction( $data, $expect_variants, $sample_html = '' ) {
		$problems = array();

		if ( '' === trim( (string) $data['title'] ) ) {
			$problems[] = 'empty title (check JSON-LD "name", <title>, og:title)';
		}
		if ( (float) $data['price'] <= 0 ) {
			$problems[] = 'empty/zero price (check JSON-LD "offers[0].price" or a stable price element)';
		}
		if ( empty( $data['images'] ) ) {
			$problems[] = 'no images extracted (check JSON-LD "image" array, og:image, gallery <img> src/srcset)';
		}
		if ( $expect_variants && ( 'variable' !== $data['type'] || empty( $data['variants'] ) ) ) {
			$problems[] = '0 variants from a variable sample (use detection jsonld_offers when Product "offers" has >1; extract via embedded_json json_path "offers" attribute_mode name_attr — NEVER chase __APOLLO_STATE__/CatalogVariant __ref pointers)';
		}

		// SWATCH audit — the failure mode that slipped through before: variants
		// were correct but swatch terms did NOT match the variation values, so
		// the storefront rendered no color swatches. Check it deterministically.
		$variants = isset( $data['variants'] ) && is_array( $data['variants'] ) ? $data['variants'] : array();
		$swatches = isset( $data['swatches'] ) && is_array( $data['swatches'] ) ? $data['swatches'] : array();

		if ( ! empty( $variants ) && ! empty( $swatches ) ) {
			foreach ( $variants as $variant ) {
				foreach ( (array) $variant['attributes'] as $value ) {
					$value = trim( (string) $value );
					if ( '' === $value ) {
						continue;
					}
					$matched = false;
					foreach ( $swatches as $swatch ) {
						foreach ( (array) $swatch['terms'] as $slug => $term ) {
							$tname = isset( $term['name'] ) ? trim( (string) $term['name'] ) : '';
							if ( $tname === $value || (string) $slug === wc_sanitize_taxonomy_name( $value ) ) {
								$matched = true;
								break 2;
							}
						}
					}
					if ( ! $matched ) {
						$problems[] = 'swatch term missing/mismatched for variation value "' . $value . '" — swatch term names must EXACTLY equal the variation values (derive both from the same offer-name parser; do not truncate to the first word)';
					}
				}
			}
		} elseif ( ! empty( $variants ) && '' !== (string) $sample_html ) {
			// Variants exist but no swatches — flag it only when the source page
			// actually carries swatch signals (color picker / inline color).
			$has_swatch_signal = ( false !== stripos( (string) $sample_html, 'color-picker' ) )
				|| ( false !== stripos( (string) $sample_html, 'swatch' ) )
				|| ( 1 === preg_match( '/--[a-zA-Z0-9]+-2\s*:\s*(#[0-9a-fA-F]{6})/', (string) $sample_html ) );
			if ( $has_swatch_signal ) {
				$problems[] = 'color swatches present in source HTML but none extracted — extract per-variant colors from the color picker / swatch element (inline or CSS-in-JS custom-property hex)';
			}
		}

		return $problems;
	}

	/**
	 * Analyze a single (unlabeled) product page and return a scraping profile.
	 *
	 * Used by the auto-detect fallback: when platform detection fails, one
	 * product page is fetched and the AI figures out how to scrape the site.
	 *
	 * @param string $html Product page HTML.
	 * @return array|WP_Error Array with 'profile' on success.
	 */
	public static function analyze_page( $html ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return new WP_Error( 'swi_ai_empty', __( 'No product HTML to analyze.', 'swi' ) );
		}

		return self::request( array( 'html' => $html ) );
	}

	/**
	 * Fix a scraping profile from a natural-language instruction.
	 *
	 * The AI is given the current profile, the data it extracted (what the
	 * user is complaining about), the product HTML to search, and the user's
	 * message; it returns a corrected profile + explanation + change list.
	 *
	 * @param string $instruction User's natural-language fix request.
	 * @param array  $profile     Current scraping profile.
	 * @param array  $data        Currently-scraped product data.
	 * @param string $html        Product HTML to search.
	 * @param array  $history     Conversation history [{role,content}].
	 * @param array  $sheet       The user's (possibly edited) product data sheet — the target the AI should aim for.
	 * @param array  $locked      Top-level sheet field keys the user marked "correct" (do NOT change).
	 * @return array|WP_Error Array with profile/explanation/changes.
	 */
	public static function fix_product( $instruction, $profile, $data, $html, $history = array(), $sheet = array(), $locked = array() ) {
		if ( '' === trim( (string) $instruction ) ) {
			return new WP_Error( 'swi_ai_fix_empty', __( 'Describe the problem you want fixed.', 'swi' ) );
		}

		$payload = array(
			'instruction' => (string) $instruction,
			'profile'     => $profile,
			'data'        => $data,
			'html'        => (string) $html,
			'history'     => is_array( $history ) ? $history : array(),
			'sheet'       => is_array( $sheet ) ? $sheet : array(),
			'locked'      => is_array( $locked ) ? array_values( array_map( 'strval', $locked ) ) : array(),
		);

		return self::request( $payload, '/fix-product', true );
	}

	/**
	 * POST a payload to the AI endpoint and return the profile.
	 *
	 * @param array  $payload     Request body fields.
	 * @param string $path        Endpoint path (default analyze).
	 * @param bool   $return_full Return the full response (profile+explanation+changes).
	 * @return array|WP_Error
	 */
	private static function request( $payload, $path = '/analyze-product', $return_full = false ) {
		// Always tag the request with the configured provider.
		if ( ! isset( $payload['provider'] ) ) {
			$payload['provider'] = self::provider();
		}

		$args = array(
			'timeout'     => 180,
			'redirection' => 5,
			'sslverify'   => (bool) (int) SWI_Plugin::get_option( 'sslverify', 1 ),
			'headers'     => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . self::token(),
			),
			'body'        => wp_json_encode( $payload ),
		);

		// The configured endpoint is the full analyze URL; derive the API base by
		// stripping its trailing action path so alternate routes (fix-product)
		// share the same host + token.
		$base = preg_replace( '#/analyze-product/?$#', '', self::endpoint() );
		$response = wp_remote_post( $base . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'swi_ai_invalid', __( 'The AI endpoint returned an unexpected response.', 'swi' ) );
		}

		if ( $code >= 400 || empty( $data['success'] ) ) {
			$message = isset( $data['error'] ) ? (string) $data['error'] : sprintf( __( 'AI request failed (HTTP %d).', 'swi' ), $code );
			return new WP_Error( 'swi_ai_' . $code, $message );
		}

		if ( empty( $data['profile'] ) || ! is_array( $data['profile'] ) ) {
			return new WP_Error( 'swi_ai_profile', __( 'The AI endpoint did not return a profile.', 'swi' ) );
		}

		if ( $return_full ) {
			return array(
				'profile'     => $data['profile'],
				'explanation' => isset( $data['explanation'] ) ? (string) $data['explanation'] : '',
				'changes'     => isset( $data['changes'] ) && is_array( $data['changes'] ) ? $data['changes'] : array(),
			);
		}

		return $data['profile'];
	}

	/**
	 * POST a raw payload and return the full decoded JSON response (for the
	 * agent endpoint, whose shape differs from the profile endpoints).
	 *
	 * @param array $payload Request body.
	 * @return array|WP_Error
	 */
	private static function request_raw( $payload ) {
		if ( ! isset( $payload['provider'] ) ) {
			$payload['provider'] = self::provider();
		}

		$args = array(
			'timeout'     => 180,
			'redirection' => 5,
			'sslverify'   => (bool) (int) SWI_Plugin::get_option( 'sslverify', 1 ),
			'headers'     => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . self::token(),
			),
			'body'        => wp_json_encode( $payload ),
		);

		$base     = preg_replace( '#/analyze-product/?$#', '', self::endpoint() );
		$response = wp_remote_post( $base . '/agent', $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'swi_ai_invalid', __( 'The AI endpoint returned an unexpected response.', 'swi' ) );
		}
		if ( $code >= 400 || empty( $data['success'] ) ) {
			$message = isset( $data['error'] ) ? (string) $data['error'] : sprintf( __( 'AI request failed (HTTP %d).', 'swi' ), $code );
			return new WP_Error( 'swi_ai_' . $code, $message );
		}

		return $data;
	}

	/**
	 * Full agent loop ("make it think like me"): instead of a single
	 * generate→audit→retry cycle, the model is given TOOLS and iterates until
	 * it considers the profile correct.
	 *
	 * Each turn the model returns ONE of:
	 *   {"action":"final","profile":{...}}
	 *   {"action":"tool","tool":"search_html","pattern":"<regex>","field":"<why>"}
	 *   {"action":"tool","tool":"run_profile","profile":{...}}
	 *   {"action":"tool","tool":"inspect","field":"<title|price|images|swatches|variants>"}
	 *
	 * The plugin executes the tool locally and feeds the result back. The loop
	 * runs for a bounded number of turns, then falls back to the best profile.
	 *
	 * @param string $html_simple   Simple sample HTML (may be '').
	 * @param string $html_variable Variable sample HTML (may be '').
	 * @param string $base_url      Sample URL.
	 * @return array|WP_Error
	 */
	public static function analyze_with_agent( $html_simple, $html_variable, $base_url = '' ) {
		if ( '' === $html_simple && '' === $html_variable ) {
			return new WP_Error( 'swi_ai_empty', __( 'Provide at least one sample product (simple or variable).', 'swi' ) );
		}

		$is_variable = '' !== $html_variable;
		$sample      = $is_variable ? $html_variable : $html_simple;

		// Bootstrap with the existing audit loop (fast path for easy sites).
		$best = self::analyze_with_audit( $html_simple, $html_variable, $base_url );
		if ( is_wp_error( $best ) ) {
			return $best;
		}

		// If the bootstrap already produced a clean profile, don't pay for the
		// agent loop.
		$data = SWI_Generic_Scraper::scrape_html( $sample, $base_url, $best );
		if ( ! is_wp_error( $data ) && empty( self::audit_extraction( $data, $is_variable, $sample ) ) ) {
			return $best;
		}

		// System prompt: the agent's charter + tool contract.
		$system = implode( "\n", array(
			'You are an expert e-commerce scraping agent. Your job is to produce a correct "scraping profile" (JSON describing how to extract product fields + variants from ANY product page on this site).',
			'',
			'You may use tools to investigate the HTML before finalizing. Every response must be a single JSON object (no markdown) with exactly one of these shapes:',
			'  {"action":"final","profile":{...}}',
			'  {"action":"tool","tool":"sources"}',
			'  {"action":"tool","tool":"search_html","pattern":"<regex>","field":"<one-word reason>"}',
			'  {"action":"tool","tool":"run_profile","profile":{...}}',
			'  {"action":"tool","tool":"inspect","field":"<title|price|images|swatches|variants|description|sku|categories>"}',
			'',
			'FIELD RULES (use these strategies): meta / jsonld / jsonld_all / selector / selector_all / regex / null.',
			'VARIANT STRATEGIES: productgroup / embedded_json / dom_options (with attribute_mode title_split|option_fields|attributes_map|name_attr).',
			'Prefer JSON-LD and <meta> tags. Never use hashed/obfuscated CSS classes.',
			'Images may live in: JSON-LD "image", og:image, <img srcset>, embedded JSON (data-props / application/json), or gallery containers. Swatch colors may be inline styles or inside embedded JSON (background-color:#hex).',
			'',
			'SOURCE MAP: your FIRST tool call should be {"action":"tool","tool":"sources"} to see exactly which data sources exist in this HTML (JSON-LD nodes, meta tags, embedded JSON/state). Base your strategy on that map — never invent a path that is not in the map.',
			'CRITICAL — VARIABLE detection: if the map shows a schema.org "Product" with offers>1 (or a "ProductGroup" with hasVariant), it IS a variable product. Use detection {"strategy":"jsonld_offers"} and extract variants from the offers array via embedded_json (json_path "offers", attribute_mode "name_attr", attribute_names [the attribute label], price_path "price", sku_path "sku", image_path "image").',
			'CRITICAL — __APOLLO_STATE__ / CatalogProduct / CatalogVariant / ROOT_QUERY are a GraphQL CLIENT CACHE whose variants are __ref pointers; dot-paths into it usually resolve to NOTHING. NEVER build a json_path into it. The same variants are also present as a schema.org JSON-LD "offers" array — use that instead.',
			'Keep using tools until every field is correct, then return {"action":"final","profile":{...}}.',
		) );

		$messages = array(
			array( 'role' => 'system', 'content' => $system ),
			array(
				'role'    => 'user',
				'content' => 'Here is the product HTML to scrape (truncated). Build a scraping profile, investigate with tools if needed, then finalize.' . "\n\n=== SOURCE INVENTORY (what exists in this HTML) ===\n" . (string) SWI_Generic_Scraper::source_inventory( $sample ) . "\n\n=== PRODUCT HTML ===\n" . substr( $sample, 0, 300000 ),
			),
		);

		$best_score = PHP_INT_MAX;

		for ( $turn = 0; $turn < 6; $turn++ ) {
			$reply = self::request_raw( array( 'messages' => $messages ) );
			if ( is_wp_error( $reply ) ) {
				break; // degrade to best profile.
			}

			$text = isset( $reply['reply'] ) ? (string) $reply['reply'] : '';
			$msg  = json_decode( $text, true );
			if ( ! is_array( $msg ) || empty( $msg['action'] ) ) {
				break; // model returned junk — stop.
			}

			// Append the model's message so it has memory.
			$messages[] = array( 'role' => 'assistant', 'content' => $text );

			if ( 'final' === $msg['action'] ) {
				if ( ! empty( $msg['profile'] ) && is_array( $msg['profile'] ) ) {
					$valid = SWI_Profile::validate( $msg['profile'] );
					if ( is_wp_error( $valid ) ) {
						break;
					}
					return $valid;
				}
				break;
			}

			if ( 'tool' !== $msg['action'] || empty( $msg['tool'] ) ) {
				break;
			}

			$result = self::run_agent_tool( $msg, $sample, $base_url );
			$messages[] = array( 'role' => 'user', 'content' => 'TOOL RESULT (' . $msg['tool'] . "):\n" . $result );

			// Track the best "run_profile" output we've seen, in case we hit the
			// turn cap before the model finalizes.
			if ( 'run_profile' === $msg['tool'] && ! empty( $msg['profile'] ) && is_array( $msg['profile'] ) ) {
				$valid = SWI_Profile::validate( $msg['profile'] );
				if ( ! is_wp_error( $valid ) ) {
					$data  = SWI_Generic_Scraper::scrape_html( $sample, $base_url, $valid );
					$score = is_wp_error( $data ) ? PHP_INT_MAX : count( self::audit_extraction( $data, $is_variable, $sample ) );
					if ( $score < $best_score ) {
						$best_score = $score;
						$best       = $valid;
					}
				}
			}
		}

		return $best;
	}

	/**
	 * Execute one agent tool locally against the sample HTML.
	 *
	 * @param array  $msg     The tool message.
	 * @param string $sample  Sample HTML.
	 * @param string $base_url Base URL.
	 * @return string Result text fed back to the model.
	 */
	private static function run_agent_tool( $msg, $sample, $base_url ) {
		$tool = (string) $msg['tool'];

		if ( 'search_html' === $tool ) {
			$pattern = isset( $msg['pattern'] ) ? (string) $msg['pattern'] : '';
			if ( '' === $pattern ) {
				return 'ERROR: search_html requires a "pattern".';
			}
			// Guard against a catastrophic regex / empty match set.
			$count = @preg_match_all( $pattern, $sample, $matches, PREG_SET_ORDER );
			if ( false === $count || 0 === $count ) {
				return 'No matches found.';
			}
			$snippets = array();
			foreach ( array_slice( $matches, 0, 10 ) as $m ) {
				$snippets[] = isset( $m[0] ) ? substr( (string) $m[0], 0, 300 ) : '';
			}
			return 'Found ' . $count . " matches. Samples:\n" . implode( "\n---\n", $snippets );
		}

		if ( 'run_profile' === $tool ) {
			if ( empty( $msg['profile'] ) || ! is_array( $msg['profile'] ) ) {
				return 'ERROR: run_profile requires a "profile".';
			}
			$data = SWI_Generic_Scraper::scrape_html( $sample, $base_url, $msg['profile'] );
			if ( is_wp_error( $data ) ) {
				return 'Scraper error: ' . $data->get_error_message();
			}
			return json_encode(
				array(
					'title'    => isset( $data['title'] ) ? $data['title'] : '',
					'price'    => isset( $data['price'] ) ? $data['price'] : '',
					'sku'      => isset( $data['sku'] ) ? $data['sku'] : '',
					'images'   => isset( $data['images'] ) ? count( $data['images'] ) : 0,
					'type'     => isset( $data['type'] ) ? $data['type'] : 'simple',
					'variants' => isset( $data['variants'] ) ? count( $data['variants'] ) : 0,
					'swatches' => isset( $data['swatches'] ) ? count( $data['swatches'] ) : 0,
					'categories' => isset( $data['categories'] ) ? $data['categories'] : array(),
				),
				JSON_UNESCAPED_UNICODE
			);
		}

		if ( 'sources' === $tool ) {
			$inv = SWI_Generic_Scraper::source_inventory( $sample );
			if ( '' === $inv ) {
				return 'No structured data sources detected (no JSON-LD, no relevant meta). Use search_html to locate fields in the raw HTML.';
			}
			return "SOURCE INVENTORY (which data sources exist in this HTML):\n" . $inv;
		}

		if ( 'inspect' === $tool ) {
			$field = isset( $msg['field'] ) ? (string) $msg['field'] : '';
			$inv   = SWI_Generic_Scraper::source_inventory( $sample );
			$hint  = array(
				'title'       => 'look in the JSON-LD Product "name", or <title>, or og:title.',
				'description' => 'look in JSON-LD "description" or <meta name="description">.',
				'price'       => 'look in JSON-LD "offers[0].price" or <meta property="product:price:amount">.',
				'sku'         => 'look in JSON-LD "sku" or <meta property="product:sku">.',
				'images'      => 'look in JSON-LD "image" (string or array), og:image, or the product gallery container.',
				'categories'  => 'look in JSON-LD BreadcrumbList itemListElement.item.name, or breadcrumb <a> links.',
				'swatches'    => 'look for inline background-color / hex, or embedded JSON swatch entries.',
				'variants'    => 'look for JSON-LD "offers" (each offer = variant) or a ProductGroup "hasVariant" array.',
			);
			$tip = isset( $hint[ $field ] ) ? $hint[ $field ] : 'inspect the source inventory below and use search_html to find the raw value.';
			return 'To locate field "' . $field . '": ' . $tip . "\n\n" . ( '' !== $inv ? $inv : 'No structured sources; use search_html.' );
		}

		return 'ERROR: unknown tool "' . $tool . '".';
	}
}
