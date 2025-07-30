
<?php
/*
Plugin Name: KE Lubricants SEO Booster
Description: Automatically generate meta title, description, tags, and alt-text using OpenAI API. Also integrates Google Keyword Planner data.
Version: 1.0
Author: Krish Yadav
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('KESEO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('KESEO_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('KESEO_VERSION', '1.0');

/**
 * Google Keyword Planner API Integration
 * Provides real-time keyword data validation and enhancement
 */
class KELubricantsGoogleAdsAPI {

    private $customer_id;
    private $developer_token;
    private $client_id;
    private $client_secret;
    private $refresh_token;
    private $access_token;

    public function __construct() {
        $this->customer_id = get_option('keseo_google_customer_id');
        $this->developer_token = get_option('keseo_google_developer_token');
        $this->client_id = get_option('keseo_google_client_id');
        $this->client_secret = get_option('keseo_google_client_secret');
        $this->refresh_token = get_option('keseo_google_refresh_token');
        $this->access_token = '';
    }

    /**
     * Get real-time keyword data from Google Keyword Planner
     */
    public function get_keyword_data($keywords, $location = 'US', $language = 'en') {
        if (empty($keywords) || !$this->is_configured()) {
            return false;
        }

        // Ensure we have a valid access token
        if (!$this->get_access_token()) {
            error_log('KE SEO: Failed to get Google Ads access token');
            return false;
        }

        $keyword_data = array();

        // Process keywords in batches of 10 (API limit)
        $keyword_batches = array_chunk($keywords, 10);

        foreach ($keyword_batches as $batch) {
            $batch_data = $this->fetch_keyword_batch($batch, $location, $language);
            if ($batch_data) {
                $keyword_data = array_merge($keyword_data, $batch_data);
            }

            // Add delay to respect rate limits
            sleep(1);
        }

        return $keyword_data;
    }

    /**
     * Fetch keyword data for a batch of keywords
     */
    private function fetch_keyword_batch($keywords, $location, $language) {
        $url = "https://googleads.googleapis.com/v15/customers/{$this->customer_id}/keywordPlanIdeas:generateKeywordIdeas";

        // Prepare keyword seeds
        $keyword_seeds = array();
        foreach ($keywords as $keyword) {
            $keyword_seeds[] = array('text' => trim($keyword));
        }

        // Request body
        $request_body = array(
            'keywordPlanNetwork' => 'GOOGLE_SEARCH',
            'geoTargetConstants' => array("geoTargets/{$this->get_location_id($location)}"),
            'language' => "languageConstants/{$this->get_language_id($language)}",
            'keywordSeed' => array(
                'keywords' => $keyword_seeds
            ),
            'includeAdultKeywords' => false
        );

        $headers = array(
            'Authorization' => 'Bearer ' . $this->access_token,
            'Developer-Token' => $this->developer_token,
            'Content-Type' => 'application/json'
        );

        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => json_encode($request_body),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            error_log('KE SEO: Google Ads API error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            error_log('KE SEO: Google Ads API returned code ' . $response_code . ': ' . $response_body);
            return false;
        }

        return $this->parse_keyword_response($response_body);
    }

    /**
     * Parse Google Ads API response
     */
    private function parse_keyword_response($response_body) {
        $data = json_decode($response_body, true);
        $keyword_data = array();

        if (isset($data['results'])) {
            foreach ($data['results'] as $result) {
                $keyword_text = isset($result['text']) ? $result['text'] : '';
                $search_volume = isset($result['keywordIdeaMetrics']['avgMonthlySearches']) ? $result['keywordIdeaMetrics']['avgMonthlySearches'] : 0;
                $competition = isset($result['keywordIdeaMetrics']['competition']) ? $result['keywordIdeaMetrics']['competition'] : 'UNKNOWN';
                $low_top_bid = isset($result['keywordIdeaMetrics']['lowTopOfPageBidMicros']) ? $result['keywordIdeaMetrics']['lowTopOfPageBidMicros'] : 0;
                $high_top_bid = isset($result['keywordIdeaMetrics']['highTopOfPageBidMicros']) ? $result['keywordIdeaMetrics']['highTopOfPageBidMicros'] : 0;

                // Convert micros to dollars
                $low_cpc = $low_top_bid / 1000000;
                $high_cpc = $high_top_bid / 1000000;
                $avg_cpc = ($low_cpc + $high_cpc) / 2;

                // Map competition levels
                $competition_score = $this->map_competition_level($competition);

                $keyword_data[$keyword_text] = array(
                    'keyword' => $keyword_text,
                    'search_volume' => intval($search_volume),
                    'competition' => $competition,
                    'competition_score' => $competition_score,
                    'avg_cpc' => round($avg_cpc, 2),
                    'low_cpc' => round($low_cpc, 2),
                    'high_cpc' => round($high_cpc, 2),
                    'commercial_intent' => $this->calculate_commercial_intent($avg_cpc, $competition_score),
                    'opportunity_score' => $this->calculate_opportunity_score($search_volume, $competition_score, $avg_cpc)
                );
            }
        }

        return $keyword_data;
    }

    /**
     * Get fresh access token using refresh token
     */
    private function get_access_token() {
        // Check if we have a cached valid token
        $cached_token = get_transient('keseo_google_access_token');
        if ($cached_token) {
            $this->access_token = $cached_token;
            return true;
        }

        // Get new access token
        $url = 'https://oauth2.googleapis.com/token';

        $body = array(
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'refresh_token' => $this->refresh_token,
            'grant_type' => 'refresh_token'
        );

        $response = wp_remote_post($url, array(
            'body' => $body,
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($response_body['access_token'])) {
            $this->access_token = $response_body['access_token'];
            $expires_in = isset($response_body['expires_in']) ? $response_body['expires_in'] : 3600;

            // Cache the token for slightly less than its expiry time
            set_transient('keseo_google_access_token', $this->access_token, $expires_in - 300);

            return true;
        }

        return false;
    }

    /**
     * Check if API is properly configured
     */
    private function is_configured() {
        return !empty($this->customer_id) && 
               !empty($this->developer_token) && 
               !empty($this->client_id) && 
               !empty($this->client_secret) && 
               !empty($this->refresh_token);
    }

    /**
     * Get location ID for geo-targeting
     */
    private function get_location_id($location) {
        $locations = array(
            'US' => '2840',
            'CA' => '2124',
            'UK' => '2826',
            'AU' => '2036',
            'DE' => '2276',
            'FR' => '2250',
            'IT' => '2380',
            'ES' => '2724',
            'BR' => '2076',
            'IN' => '2356',
            'JP' => '2392'
        );

        return isset($locations[$location]) ? $locations[$location] : '2840'; // Default to US
    }

    /**
     * Get language ID
     */
    private function get_language_id($language) {
        $languages = array(
            'en' => '1000',
            'es' => '1003',
            'fr' => '1002',
            'de' => '1001',
            'it' => '1004',
            'pt' => '1014',
            'ja' => '1005',
            'zh' => '1017'
        );

        return isset($languages[$language]) ? $languages[$language] : '1000'; // Default to English
    }

    /**
     * Map competition level to numeric score
     */
    private function map_competition_level($competition) {
        switch ($competition) {
            case 'LOW':
                return 25;
            case 'MEDIUM':
                return 50;
            case 'HIGH':
                return 75;
            default:
                return 50;
        }
    }

    /**
     * Calculate commercial intent score
     */
    private function calculate_commercial_intent($avg_cpc, $competition_score) {
        // Higher CPC and competition usually indicate commercial intent
        $cpc_score = min($avg_cpc * 10, 50); // Cap at 50
        $intent_score = ($cpc_score + $competition_score) / 2;

        return min(round($intent_score), 100);
    }

    /**
     * Calculate opportunity score
     */
    private function calculate_opportunity_score($search_volume, $competition_score, $avg_cpc) {
        // Higher volume is good, lower competition is good, moderate CPC is good
        $volume_score = min($search_volume / 1000, 50); // Normalize volume
        $competition_penalty = $competition_score * 0.5; // Lower is better
        $cpc_score = min($avg_cpc * 5, 25); // Sweet spot around $2-5

        $opportunity = $volume_score - $competition_penalty + $cpc_score;

        return max(0, min(round($opportunity), 100));
    }

    /**
     * Validate keywords with real Google data
     */
    public function validate_ai_keywords($ai_keywords, $location = 'US') {
        if (empty($ai_keywords)) {
            return array();
        }

        // Extract just the keyword strings if array of objects
        $keyword_strings = array();
        foreach ($ai_keywords as $keyword) {
            if (is_string($keyword)) {
                $keyword_strings[] = $keyword;
            } elseif (is_array($keyword) && isset($keyword['keyword'])) {
                $keyword_strings[] = $keyword['keyword'];
            }
        }

        $google_data = $this->get_keyword_data($keyword_strings, $location);

        if (!$google_data) {
            return $ai_keywords; // Return original if validation fails
        }

        $validated_keywords = array();

        if (is_array($ai_keywords)) {
            foreach ($ai_keywords as $index => $ai_keyword) {
            $keyword_text = is_string($ai_keyword) ? $ai_keyword : $ai_keyword['keyword'];

            if (isset($google_data[$keyword_text])) {
                $google_info = $google_data[$keyword_text];

                $validated_keywords[] = array(
                    'keyword' => $keyword_text,
                    'ai_suggested' => true,
                    'search_volume' => $google_info['search_volume'],
                    'competition' => $google_info['competition'],
                    'competition_score' => $google_info['competition_score'],
                    'avg_cpc' => $google_info['avg_cpc'],
                    'commercial_intent' => $google_info['commercial_intent'],
                    'opportunity_score' => $google_info['opportunity_score'],
                    'validation_status' => $this->get_validation_status($google_info),
                    'recommendation' => $this->get_keyword_recommendation($google_info)
                );
            } else {
                // Keep AI keyword but mark as unvalidated
                $validated_keywords[] = array(
                    'keyword' => $keyword_text,
                    'ai_suggested' => true,
                    'validation_status' => 'unvalidated',
                    'recommendation' => 'Manual review recommended - no Google data available'
                );
            }
        }
        }

        return $validated_keywords;
    }

    /**
     * Get validation status based on Google data
     */
    private function get_validation_status($google_info) {
        $volume = $google_info['search_volume'];
        $opportunity = $google_info['opportunity_score'];

        if ($volume < 10) {
            return 'low_volume';
        } elseif ($opportunity > 60) {
            return 'high_opportunity';
        } elseif ($opportunity > 30) {
            return 'good_opportunity';
        } else {
            return 'challenging';
        }
    }

    /**
     * Get keyword recommendation
     */
    private function get_keyword_recommendation($google_info) {
        $volume = $google_info['search_volume'];
        $competition = $google_info['competition_score'];
        $opportunity = $google_info['opportunity_score'];

        if ($volume < 10) {
            return 'Consider for long-tail strategy - very low search volume';
        } elseif ($opportunity > 70) {
            return 'High priority - excellent opportunity';
        } elseif ($opportunity > 50) {
            return 'Good target - moderate opportunity';
        } elseif ($competition > 70) {
            return 'High competition - requires strong content strategy';
        } else {
            return 'Research further - mixed signals';
        }
    }

    /**
     * Get suggested keywords from Google (beyond AI suggestions)
     */
    public function get_suggested_keywords($seed_keywords, $limit = 50, $location = 'US') {
        if (!$this->is_configured()) {
            return array();
        }

        // Get broader keyword suggestions from Google
        $suggestions = $this->fetch_keyword_suggestions($seed_keywords, $limit, $location);

        // Filter and score suggestions
        $filtered_suggestions = array();
        foreach ($suggestions as $suggestion) {
            if ($suggestion['search_volume'] >= 10 && $suggestion['opportunity_score'] > 20) {
                $filtered_suggestions[] = $suggestion;
            }
        }

        // Sort by opportunity score
        usort($filtered_suggestions, function($a, $b) {
            return $b['opportunity_score'] - $a['opportunity_score'];
        });

        return array_slice($filtered_suggestions, 0, $limit);
    }

    /**
     * Fetch keyword suggestions from Google Ads API
     */
    private function fetch_keyword_suggestions($seed_keywords, $limit, $location) {
        // This would use the same API but with broader parameters
        // to get more keyword ideas beyond the AI suggestions
        return $this->get_keyword_data($seed_keywords, $location);
    }

    /**
     * Test API connection
     */
    public function test_connection() {
        if (!$this->is_configured()) {
            return array(
                'success' => false,
                'message' => 'API credentials not configured'
            );
        }

        if (!$this->get_access_token()) {
            return array(
                'success' => false,
                'message' => 'Failed to get access token'
            );
        }

        // Test with a simple keyword
        $test_data = $this->get_keyword_data(array('lubricants'));

        if ($test_data) {
            return array(
                'success' => true,
                'message' => 'Google Keyword Planner API connected successfully',
                'test_data' => $test_data
            );
        } else {
            return array(
                'success' => false,
                'message' => 'API connection failed'
            );
        }
    }
}

class KELubricantsSEOBooster {

    private $google_api;

    public function __construct() {
        // Initialize Google API
        $this->google_api = new KELubricantsGoogleAdsAPI();
        
        // Admin hooks - use proper priority and ensure they run
        add_action('admin_menu', array($this, 'create_admin_menu'), 10);
        add_action('admin_init', array($this, 'register_settings'), 10);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'), 10);
        
        // Post editor hooks
        add_action('add_meta_boxes', array($this, 'add_seo_meta_box'), 10);
        add_action('save_post', array($this, 'generate_seo_data'), 20, 2);
        
        // Frontend hooks
        add_action('wp_head', array($this, 'output_schema_markup'), 10);
        add_action('wp_head', array($this, 'output_open_graph_tags'), 10);
        
        // AJAX hooks
        add_action('wp_ajax_keseo_generate_preview', array($this, 'ajax_generate_preview'));
        add_action('wp_ajax_keseo_generate_targeted', array($this, 'ajax_generate_targeted'));
        add_action('wp_ajax_keseo_generate_longtail', array($this, 'ajax_generate_longtail'));
        add_action('wp_ajax_keseo_analyze_competition', array($this, 'ajax_analyze_competition'));
        add_action('wp_ajax_keseo_bulk_generate', array($this, 'ajax_bulk_generate'));
        add_action('wp_ajax_keseo_test_api', array($this, 'ajax_test_api'));
        add_action('wp_ajax_keseo_test_google_api', array($this, 'ajax_test_google_api'));
        add_action('wp_ajax_keseo_get_analysis', array($this, 'ajax_get_analysis'));
        add_action('wp_ajax_keseo_get_dashboard_stats', array($this, 'ajax_get_dashboard_stats'));
        add_action('wp_ajax_keseo_get_posts_table', array($this, 'ajax_get_posts_table'));
        add_action('wp_ajax_keseo_export_csv', array($this, 'ajax_export_csv'));
        add_action('wp_ajax_keseo_auto_optimize', array($this, 'ajax_auto_optimize'));
        add_action('wp_ajax_keseo_bulk_preview', array($this, 'ajax_bulk_preview'));
        add_action('wp_ajax_keseo_bulk_generate', array($this, 'ajax_bulk_generate'));
        
        // Sitemap enhancement
        add_filter('wp_sitemaps_posts_entry', array($this, 'enhance_sitemap_entry'), 10, 3);
        
        // Admin notices
        add_action('admin_notices', array($this, 'show_admin_notices'));
        
        // Debug hook to verify plugin is loaded
        add_action('init', array($this, 'debug_plugin_loaded'));
    }

    public function debug_plugin_loaded() {
        // Debug function to verify plugin is working
        if (is_admin() && current_user_can('manage_options')) {
            error_log('KE SEO Booster: Plugin loaded successfully');
        }
    }

    public function create_admin_menu() {
        // Ensure we're in admin area
        if (!is_admin()) {
            return;
        }

        // Main settings page under Settings menu
        $page_hook = add_options_page(
            'KE SEO Booster Settings',  // Page title
            'KE SEO Booster',          // Menu title
            'manage_options',          // Capability
            'ke-seo-booster',         // Menu slug
            array($this, 'settings_page') // Callback
        );

        // Log for debugging
        if ($page_hook) {
            error_log('KE SEO Booster: Admin menu created successfully');
        } else {
            error_log('KE SEO Booster: Failed to create admin menu');
        }

        // Add SEO Analysis as a separate menu item
        add_submenu_page(
            'options-general.php',     // Parent slug
            'SEO Analysis',            // Page title
            'SEO Analysis',            // Menu title
            'manage_options',          // Capability
            'ke-seo-analysis',        // Menu slug
            array($this, 'analysis_page') // Callback
        );

        // Add SEO Dashboard
        add_submenu_page(
            'options-general.php',     // Parent slug
            'SEO Dashboard',           // Page title
            'SEO Dashboard',           // Menu title
            'manage_options',          // Capability
            'ke-seo-dashboard',       // Menu slug
            array($this, 'dashboard_page') // Callback
        );
    }

    public function add_seo_meta_box() {
        $supported_types = get_option('keseo_post_types', array('post', 'page', 'product'));
        
        foreach ($supported_types as $post_type) {
            add_meta_box(
                'keseo_seo_meta_box',
                'KE SEO Booster',
                array($this, 'seo_meta_box_callback'),
                $post_type,
                'side',
                'high'
            );
        }
    }

    public function seo_meta_box_callback($post) {
        wp_nonce_field('keseo_meta_box', 'keseo_meta_box_nonce');
        
        // Get existing meta values
        $seo_title = get_post_meta($post->ID, '_keseo_title', true);
        $seo_description = get_post_meta($post->ID, '_keseo_description', true);
        $focus_keyword = get_post_meta($post->ID, '_keseo_focus_keyword', true);
        $focused_seo_words = get_post_meta($post->ID, '_keseo_focused_seo_words', true);
        $longtail_keywords = get_post_meta($post->ID, '_keseo_longtail_keywords', true);
        $target_audience = get_post_meta($post->ID, '_keseo_target_audience', true);
        $seo_intent = get_post_meta($post->ID, '_keseo_seo_intent', true);
        $target_location = get_post_meta($post->ID, '_keseo_target_location', true);
        $custom_alt_text = get_post_meta($post->ID, '_keseo_custom_alt_text', true);
        
        // Get images count for this post
        $images = get_attached_media('image', $post->ID);
        $images_count = count($images);
        
        echo '<div class="keseo-meta-box">';
        
        // 🎯 PRIORITY 1: Core SEO Fields (Per-Page Override)
        echo '<div class="keseo-section keseo-priority">';
        echo '<h4><span class="priority-badge">HIGH PRIORITY</span> 🎯 Core SEO Settings (Per-Page Override)</h4>';
        echo '<div class="keseo-field-row">';
        echo '<div class="keseo-field-half">';
        echo '<label><strong>Focus Keyword(s):</strong></label>';
        echo '<input type="text" name="keseo_focus_keyword" value="' . esc_attr($focus_keyword) . '" style="width:100%;" placeholder="e.g., MP3 grease for heavy vehicles" />';
        echo '<small>Primary keyword(s) for this specific page</small>';
        echo '</div>';
        echo '<div class="keseo-field-half">';
        echo '<label><strong>📍 Target Location:</strong></label>';
        echo '<input type="text" name="keseo_target_location" value="' . esc_attr($target_location) . '" style="width:100%;" placeholder="e.g., Mumbai, Navi Mumbai" />';
        echo '<small>Location for local SEO targeting</small>';
        echo '</div>';
        echo '</div>';
        
        echo '<p><label><strong>Meta Title (Max 60 chars):</strong></label>';
        echo '<input type="text" name="keseo_title" value="' . esc_attr($seo_title) . '" style="width:100%;" maxlength="60" placeholder="Leave blank to use global settings" />';
        echo '<small id="title-counter" class="char-counter">0/60 characters</small></p>';
        
        echo '<p><label><strong>Meta Description (130-160 chars):</strong></label>';
        echo '<textarea name="keseo_description" rows="3" style="width:100%;" maxlength="160" placeholder="Leave blank to use global settings">' . esc_textarea($seo_description) . '</textarea>';
        echo '<small id="description-counter" class="char-counter">0/160 characters</small></p>';
        
        echo '<p><label><strong>🖼️ Custom Alt Text for Images:</strong></label>';
        echo '<textarea name="keseo_custom_alt_text" rows="2" style="width:100%;" placeholder="Auto-apply to all images on this page (optional)">' . esc_textarea($custom_alt_text) . '</textarea>';
        echo '<small>Found ' . $images_count . ' images. Alt text will be applied to all images on save.</small></p>';
        echo '</div>';

        // Advanced Strategy Section
        echo '<div class="keseo-section">';
        echo '<h4>🧠 Advanced SEO Strategy</h4>';
        echo '<div class="keseo-field-row">';
        echo '<div class="keseo-field-half">';
        echo '<label><strong>Focused SEO Words:</strong></label>';
        echo '<textarea name="keseo_focused_seo_words" rows="3" style="width:100%;" placeholder="Additional keywords for this page">' . esc_textarea($focused_seo_words) . '</textarea>';
        echo '<small>Secondary keywords (comma-separated)</small>';
        echo '</div>';
        echo '<div class="keseo-field-half">';
        echo '<label><strong>Long-tail Keywords:</strong></label>';
        echo '<textarea name="keseo_longtail_keywords" rows="3" style="width:100%;" placeholder="Long-tail variations">' . esc_textarea($longtail_keywords) . '</textarea>';
        echo '<small>Specific long-tail variations</small>';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="keseo-field-row">';
        echo '<div class="keseo-field-half">';
        echo '<label><strong>Target Audience:</strong></label>';
        echo '<input type="text" name="keseo_target_audience" value="' . esc_attr($target_audience) . '" style="width:100%;" placeholder="Construction companies in Mumbai" />';
        echo '<small>Who is this content for?</small>';
        echo '</div>';
        echo '<div class="keseo-field-half">';
        echo '<label><strong>SEO Intent:</strong></label>';
        echo '<select name="keseo_seo_intent" style="width:100%;">';
        echo '<option value="">Select Intent</option>';
        echo '<option value="informational"' . selected($seo_intent, 'informational', false) . '>Informational</option>';
        echo '<option value="commercial"' . selected($seo_intent, 'commercial', false) . '>Commercial</option>';
        echo '<option value="transactional"' . selected($seo_intent, 'transactional', false) . '>Transactional</option>';
        echo '<option value="navigational"' . selected($seo_intent, 'navigational', false) . '>Navigational</option>';
        echo '</select>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Action Buttons
        echo '<div class="keseo-actions">';
        echo '<button type="button" id="keseo-generate-targeted" class="button button-primary" data-post-id="' . $post->ID . '">🎯 Generate Location-Based SEO</button>';
        echo '<button type="button" id="keseo-generate-longtail" class="button" data-post-id="' . $post->ID . '">📝 Generate Long-tail Focus</button>';
        echo '<button type="button" id="keseo-auto-optimize" class="button button-secondary" data-post-id="' . $post->ID . '">⚡ Auto-Optimize All Fields</button>';
        echo '<button type="button" id="keseo-apply-alt-text" class="button">🖼️ Apply Alt Text to Images</button>';
        echo '</div>';

        // SEO Preview
        echo '<div id="keseo-seo-preview" style="margin-top: 20px;"></div>';
        
        // SEO Health Score
        echo '<div class="keseo-health-score">';
        echo '<h4>📊 SEO Health Score</h4>';
        echo '<div id="keseo-score-display">';
        echo '<div class="score-circle" id="score-circle"><span id="score-number">0</span>%</div>';
        echo '<div class="score-details" id="score-details">';
        echo '<div class="score-item" id="score-title">❌ Meta Title</div>';
        echo '<div class="score-item" id="score-description">❌ Meta Description</div>';
        echo '<div class="score-item" id="score-keyword">❌ Focus Keyword</div>';
        echo '<div class="score-item" id="score-location">❌ Target Location</div>';
        echo '<div class="score-item" id="score-images">❌ Image Alt Text</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        echo '</div>';
        
        // Enhanced CSS and JavaScript
        echo '<style>
        .keseo-meta-box { background: #f9f9f9; padding: 15px; border-radius: 5px; }
        .keseo-section { margin-bottom: 20px; padding: 15px; background: white; border-radius: 4px; border-left: 4px solid #0073aa; }
        .keseo-priority { border-left-color: #dc3232; }
        .priority-badge { background: #dc3232; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; }
        .keseo-section h4 { margin-top: 0; color: #0073aa; }
        .keseo-field-row { display: flex; gap: 15px; margin-bottom: 15px; }
        .keseo-field-half { flex: 1; }
        .keseo-actions { text-align: center; margin: 20px 0; }
        .keseo-actions .button { margin: 0 5px; }
        .char-counter { display: block; margin-top: 5px; font-weight: bold; }
        .char-counter.warning { color: orange; }
        .char-counter.danger { color: red; }
        .char-counter.good { color: green; }
        
        /* SEO Health Score */
        .keseo-health-score { background: white; padding: 15px; border-radius: 4px; border-left: 4px solid #46b450; }
        #keseo-score-display { display: flex; align-items: center; gap: 20px; }
        .score-circle { width: 80px; height: 80px; border-radius: 50%; background: conic-gradient(#46b450 0deg, #dc3232 0deg); display: flex; align-items: center; justify-content: center; position: relative; }
        .score-circle::before { content: ""; position: absolute; width: 60px; height: 60px; background: white; border-radius: 50%; }
        #score-number { position: relative; z-index: 1; font-weight: bold; font-size: 16px; }
        .score-details { flex: 1; }
        .score-item { margin: 5px 0; font-size: 14px; }
        .score-item.complete { color: #46b450; }
        .score-item.incomplete { color: #dc3232; }
        
        #keseo-seo-preview { padding: 15px; background: white; border: 1px solid #ddd; border-radius: 4px; display: none; }
        
        @media (max-width: 768px) {
            .keseo-field-row { flex-direction: column; gap: 10px; }
            #keseo-score-display { flex-direction: column; text-align: center; }
        }
        </style>';
        
        echo '<script>
        jQuery(document).ready(function($) {
            // Character counters with improved styling
            function updateCounter(input, counter, max) {
                var count = $(input).val().length;
                var $counter = $(counter);
                $counter.text(count + "/" + max + " characters");
                
                $counter.removeClass("good warning danger");
                if (count === 0) {
                    $counter.addClass("incomplete");
                } else if (count > max) {
                    $counter.addClass("danger");
                } else if (count > max * 0.9) {
                    $counter.addClass("warning");
                } else if (count >= max * 0.5) {
                    $counter.addClass("good");
                }
            }

            // Update SEO health score
            function updateSEOScore() {
                var score = 0;
                var total = 5;
                
                // Check each field
                var checks = {
                    title: $("input[name=keseo_title]").val().length > 0,
                    description: $("textarea[name=keseo_description]").val().length >= 130,
                    keyword: $("input[name=keseo_focus_keyword]").val().length > 0,
                    location: $("input[name=keseo_target_location]").val().length > 0,
                    images: $("textarea[name=keseo_custom_alt_text]").val().length > 0
                };
                
                // Update score items
                $("#score-title").removeClass("complete incomplete").addClass(checks.title ? "complete" : "incomplete").text((checks.title ? "✅" : "❌") + " Meta Title");
                $("#score-description").removeClass("complete incomplete").addClass(checks.description ? "complete" : "incomplete").text((checks.description ? "✅" : "❌") + " Meta Description");
                $("#score-keyword").removeClass("complete incomplete").addClass(checks.keyword ? "complete" : "incomplete").text((checks.keyword ? "✅" : "❌") + " Focus Keyword");
                $("#score-location").removeClass("complete incomplete").addClass(checks.location ? "complete" : "incomplete").text((checks.location ? "✅" : "❌") + " Target Location");
                $("#score-images").removeClass("complete incomplete").addClass(checks.images ? "complete" : "incomplete").text((checks.images ? "✅" : "❌") + " Image Alt Text");
                
                // Calculate score
                for (var key in checks) {
                    if (checks.hasOwnProperty(key) && checks[key]) {
                        score++;
                    }
                }
                
                var percentage = Math.round((score / total) * 100);
                $("#score-number").text(percentage);
                
                // Update circle color
                var degrees = (percentage / 100) * 360;
                $("#score-circle").css("background", "conic-gradient(#46b450 " + degrees + "deg, #dc3232 " + degrees + "deg)");
            }

            // Bind events
            $("input[name=keseo_title]").on("input", function() {
                updateCounter(this, "#title-counter", 60);
                updateSEOScore();
            }).trigger("input");

            $("textarea[name=keseo_description]").on("input", function() {
                updateCounter(this, "#description-counter", 160);
                updateSEOScore();
            }).trigger("input");

            $("input[name=keseo_focus_keyword], input[name=keseo_target_location], textarea[name=keseo_custom_alt_text]").on("input", updateSEOScore);
            
            // Initialize score
            updateSEOScore();

            // Apply Alt Text to Images button
            $("#keseo-apply-alt-text").on("click", function() {
                var altText = $("textarea[name=keseo_custom_alt_text]").val();
                if (!altText) {
                    alert("Please enter alt text first.");
                    return;
                }
                
                alert("Alt text will be applied to all images when the post is saved. Found ' . $images_count . ' images to update.");
            });
            
            // Auto-optimize button
            $("#keseo-auto-optimize").on("click", function() {
                var postId = $(this).data("post-id");
                var keyword = $("input[name=keseo_focus_keyword]").val();
                var location = $("input[name=keseo_target_location]").val();
                
                if (!keyword) {
                    alert("Please enter a focus keyword first.");
                    return;
                }
                
                $(this).prop("disabled", true).text("⚡ Optimizing...");
                
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "keseo_auto_optimize",
                        post_id: postId,
                        keyword: keyword,
                        location: location,
                        nonce: "' . wp_create_nonce('keseo_nonce') . '"
                    },
                    success: function(response) {
                        if (response.success) {
                            $("input[name=keseo_title]").val(response.data.title);
                            $("textarea[name=keseo_description]").val(response.data.description);
                            if (response.data.alt_text) {
                                $("textarea[name=keseo_custom_alt_text]").val(response.data.alt_text);
                            }
                            updateSEOScore();
                            alert("SEO fields auto-optimized successfully!");
                        } else {
                            alert("Error: " + response.data);
                        }
                    },
                    complete: function() {
                        $("#keseo-auto-optimize").prop("disabled", false).text("⚡ Auto-Optimize All Fields");
                    }
                });
            });
        });
        </script>';
    }

    public function register_settings() {
        register_setting('kelubricants_seo_group', 'kelubricants_openai_api_key', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('kelubricants_seo_group', 'keseo_auto_generate', array(
            'default' => '1'
        ));
        register_setting('kelubricants_seo_group', 'keseo_post_types', array(
            'default' => array('post', 'page', 'product')
        ));
        register_setting('kelubricants_seo_group', 'keseo_enable_schema', array(
            'default' => '1'
        ));
        register_setting('kelubricants_seo_group', 'keseo_enable_og_tags', array(
            'default' => '1'
        ));
        register_setting('kelubricants_seo_group', 'keseo_focus_keywords', array(
            'default' => 'lubricants, automotive oil, engine oil, industrial lubricants'
        ));

        // Google Ads API settings
        register_setting('kelubricants_seo_group', 'keseo_google_customer_id', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('kelubricants_seo_group', 'keseo_google_developer_token', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('kelubricants_seo_group', 'keseo_google_client_id', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('kelubricants_seo_group', 'keseo_google_client_secret', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('kelubricants_seo_group', 'keseo_google_refresh_token', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting('kelubricants_seo_group', 'keseo_enable_google_validation', array(
            'default' => '0'
        ));
    }

    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'ke-seo-booster') !== false || 
            strpos($hook, 'post.php') !== false || 
            strpos($hook, 'post-new.php') !== false) {

            wp_enqueue_script('keseo-admin', KESEO_PLUGIN_URL . 'admin.js', array('jquery'), KESEO_VERSION, true);
            wp_enqueue_style('keseo-admin', KESEO_PLUGIN_URL . 'admin.css', array(), KESEO_VERSION);

            wp_localize_script('keseo-admin', 'keseo_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('keseo_nonce'),
                'post_id' => isset($_GET['post']) ? intval($_GET['post']) : 0
            ));
        }
    }

    public function show_admin_notices() {
        $api_key = get_option('kelubricants_openai_api_key');
        $current_screen = get_current_screen();
        
        // Only show notice if not on our settings page and API key is missing
        if (empty($api_key) && $current_screen && $current_screen->id !== 'settings_page_ke-seo-booster') {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>KE SEO Booster:</strong> Please <a href="' . admin_url('options-general.php?page=ke-seo-booster') . '">configure your OpenAI API key</a> to start generating SEO content.</p>';
            echo '</div>';
        }
    }

    public function settings_page() {
        // Verify user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $api_key = get_option('kelubricants_openai_api_key');
        $auto_generate = get_option('keseo_auto_generate', '1');
        $post_types = get_option('keseo_post_types', array('post', 'page', 'product'));
        $enable_schema = get_option('keseo_enable_schema', '1');
        $enable_og = get_option('keseo_enable_og_tags', '1');
        $focus_keywords = get_option('keseo_focus_keywords', '');
        $enable_google = get_option('keseo_enable_google_validation', '0');
        ?>
        <div class="wrap">
            <h1>KE Lubricants SEO Booster Settings</h1>
            
            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Settings saved successfully!</p>
                </div>
            <?php endif; ?>

            <div class="nav-tab-wrapper">
                <a href="#general" class="nav-tab nav-tab-active">General</a>
                <a href="#google-api" class="nav-tab">Google API</a>
                <a href="#advanced" class="nav-tab">Advanced</a>
                <a href="#bulk-actions" class="nav-tab">Bulk Actions</a>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('kelubricants_seo_group'); ?>

                <div id="general" class="tab-content">
                    <table class="form-table">
                        <tr>
                            <th scope="row">OpenAI API Key</th>
                            <td>
                                <input type="password" name="kelubricants_openai_api_key" value="<?php echo esc_attr($api_key); ?>" style="width:400px;" />
                                <p class="description">Get your API key from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI dashboard</a></p>
                                <button type="button" id="test-api-key" class="button">Test API Key</button>
                                <span id="api-test-result"></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Auto Generate SEO</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="keseo_auto_generate" value="1" <?php checked($auto_generate, '1'); ?> />
                                    Automatically generate SEO data when posts are saved
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Focus Keywords</th>
                            <td>
                                <textarea name="keseo_focus_keywords" rows="3" style="width:400px;"><?php echo esc_textarea($focus_keywords); ?></textarea>
                                <p class="description">Comma-separated keywords to focus on for your business</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="google-api" class="tab-content" style="display:none;">
                    <h3>Google Keyword Planner Integration</h3>
                    <p>Connect to Google Keyword Planner for real-time keyword data validation and enhanced search volume accuracy.</p>

                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable Google Validation</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="keseo_enable_google_validation" value="1" <?php checked($enable_google, '1'); ?> />
                                    Validate AI keywords with real Google Keyword Planner data
                                </label>
                                <p class="description">This will enhance keyword selection accuracy but requires Google Ads API access.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Customer ID</th>
                            <td>
                                <input type="text" name="keseo_google_customer_id" value="<?php echo esc_attr(get_option('keseo_google_customer_id')); ?>" style="width:300px;" placeholder="123-456-7890" />
                                <p class="description">Your Google Ads Customer ID (found in your Google Ads account)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Developer Token</th>
                            <td>
                                <input type="password" name="keseo_google_developer_token" value="<?php echo esc_attr(get_option('keseo_google_developer_token')); ?>" style="width:400px;" />
                                <p class="description">Google Ads API Developer Token</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Client ID</th>
                            <td>
                                <input type="text" name="keseo_google_client_id" value="<?php echo esc_attr(get_option('keseo_google_client_id')); ?>" style="width:400px;" />
                                <p class="description">OAuth 2.0 Client ID from Google Cloud Console</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Client Secret</th>
                            <td>
                                <input type="password" name="keseo_google_client_secret" value="<?php echo esc_attr(get_option('keseo_google_client_secret')); ?>" style="width:400px;" />
                                <p class="description">OAuth 2.0 Client Secret from Google Cloud Console</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Refresh Token</th>
                            <td>
                                <input type="password" name="keseo_google_refresh_token" value="<?php echo esc_attr(get_option('keseo_google_refresh_token')); ?>" style="width:400px;" />
                                <p class="description">OAuth 2.0 Refresh Token for API access</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Test Connection</th>
                            <td>
                                <button type="button" id="test-google-api" class="button">Test Google API Connection</button>
                                <span id="google-api-test-result"></span>
                                <p class="description">Test your Google Ads API configuration</p>
                            </td>
                        </tr>
                    </table>

                    <div class="google-api-setup-guide">
                        <h4>Setup Instructions</h4>
                        <ol>
                            <li>Create a Google Cloud Project and enable the Google Ads API</li>
                            <li>Set up OAuth 2.0 credentials in Google Cloud Console</li>
                            <li>Apply for Google Ads API access and get your Developer Token</li>
                            <li>Generate a refresh token using the OAuth 2.0 playground</li>
                            <li>Enter your Google Ads Customer ID from your Google Ads account</li>
                        </ol>
                        <p><a href="https://developers.google.com/google-ads/api/docs/first-call/overview" target="_blank">View detailed setup guide →</a></p>
                    </div>
                </div>

                <div id="advanced" class="tab-content" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Supported Post Types</th>
                            <td>
                                <?php
                                $available_post_types = get_post_types(array('public' => true), 'objects');
                                foreach ($available_post_types as $post_type) {
                                    $checked = in_array($post_type->name, $post_types) ? 'checked' : '';
                                    echo '<label><input type="checkbox" name="keseo_post_types[]" value="' . esc_attr($post_type->name) . '" ' . $checked . '> ' . esc_html($post_type->labels->name) . '</label><br>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Schema Markup</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="keseo_enable_schema" value="1" <?php checked($enable_schema, '1'); ?> />
                                    Enable automatic schema markup generation
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Open Graph Tags</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="keseo_enable_og_tags" value="1" <?php checked($enable_og, '1'); ?> />
                                    Enable Open Graph meta tags for social media
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="bulk-actions" class="tab-content" style="display:none;">
                    <h3>📦 Bulk SEO Auto-Optimizer</h3>
                    <p>Generate location-based SEO for multiple posts/products at once.</p>
                    
                    <div class="bulk-options">
                        <div class="bulk-option-row">
                            <div class="bulk-field">
                                <label><strong>Base Keywords (Optional):</strong></label>
                                <input type="text" id="bulk-keywords" placeholder="e.g., industrial lubricants, automotive oil" style="width: 100%;">
                                <small>Will be combined with individual post keywords</small>
                            </div>
                            <div class="bulk-field">
                                <label><strong>Target Location (Optional):</strong></label>
                                <input type="text" id="bulk-location" placeholder="e.g., Mumbai, Navi Mumbai" style="width: 100%;">
                                <small>Will be added to all generated SEO</small>
                            </div>
                        </div>
                        
                        <div class="bulk-option-row">
                            <div class="bulk-field">
                                <label><strong>Post Types:</strong></label>
                                <select id="bulk-post-types" multiple style="width: 100%; height: 100px;">
                                    <option value="post" selected>Posts</option>
                                    <option value="page" selected>Pages</option>
                                    <option value="product" selected>Products</option>
                                </select>
                                <small>Hold Ctrl/Cmd to select multiple</small>
                            </div>
                            <div class="bulk-field">
                                <label><strong>Generation Mode:</strong></label>
                                <select id="bulk-mode" style="width: 100%;">
                                    <option value="missing">Only Missing SEO</option>
                                    <option value="all">Regenerate All</option>
                                    <option value="partial">Only Incomplete SEO</option>
                                </select>
                                <small>Choose what to optimize</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bulk-actions-buttons">
                        <button type="button" id="bulk-preview" class="button">📋 Preview Changes</button>
                        <button type="button" id="bulk-generate-seo" class="button button-primary">🚀 Generate SEO for All</button>
                    </div>
                    
                    <div id="bulk-preview-results" style="margin-top: 20px;"></div>
                    <div id="bulk-progress" style="margin-top: 20px;"></div>
                </div>

                <?php submit_button(); ?>
            </form>
        </div>

        <style>
        .tab-content { margin-top: 20px; }
        .nav-tab-wrapper { margin-bottom: 0; }
        #api-test-result, #google-api-test-result { margin-left: 10px; }
        .success { color: green; }
        .error { color: red; }
        #bulk-progress { padding: 10px; background: #f1f1f1; border-radius: 4px; display: none; }
        .google-api-setup-guide { background: #f9f9f9; padding: 15px; border-radius: 4px; margin-top: 15px; }
        
        /* Bulk Actions Styling */
        .bulk-options { background: #f9f9f9; padding: 20px; border-radius: 4px; margin: 20px 0; }
        .bulk-option-row { display: flex; gap: 20px; margin-bottom: 20px; }
        .bulk-field { flex: 1; }
        .bulk-field label { display: block; margin-bottom: 5px; color: #0073aa; font-weight: bold; }
        .bulk-field small { display: block; color: #666; font-style: italic; margin-top: 5px; }
        .bulk-actions-buttons { text-align: center; margin: 20px 0; }
        .bulk-actions-buttons .button { margin: 0 10px; }
        #bulk-preview-results { background: white; border: 1px solid #ddd; border-radius: 4px; padding: 15px; display: none; }
        .bulk-preview-item { padding: 10px; border-bottom: 1px solid #eee; }
        .bulk-preview-item:last-child { border-bottom: none; }
        .bulk-preview-title { font-weight: bold; color: #0073aa; }
        .bulk-preview-status { margin-left: 10px; font-size: 12px; }
        .bulk-preview-status.missing { color: #dc3232; }
        .bulk-preview-status.partial { color: #f56e28; }
        .bulk-preview-status.complete { color: #46b450; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Tab switching
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide();
                $($(this).attr('href')).show();
            });
        });
        </script>
        <?php
    }

    public function analysis_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        ?>
        <div class="wrap">
            <h1>SEO Analysis</h1>
            <div id="seo-analysis-results">
                <p>Loading SEO analysis...</p>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $.post(ajaxurl, {
                action: 'keseo_get_analysis',
                nonce: '<?php echo wp_create_nonce('keseo_nonce'); ?>'
            }, function(response) {
                $('#seo-analysis-results').html(response.data);
            });
        });
        </script>
        <?php
    }

    public function generate_seo_data($post_id, $post) {
        // Save custom meta fields first
        $this->save_custom_meta_fields($post_id);
        
        // Skip if conditions not met
        if (wp_is_post_revision($post_id) || 
            (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) ||
            !get_option('keseo_auto_generate', '1')) {
            return;
        }

        // Check if post type is supported
        $supported_types = get_option('keseo_post_types', array('post', 'page', 'product'));
        if (!in_array($post->post_type, $supported_types)) {
            return;
        }

        // Get API key
        $api_key = get_option('kelubricants_openai_api_key');
        if (empty($api_key)) {
            $this->log_error('OpenAI API key not configured', $post_id);
            return;
        }

        // Check if SEO data already exists (avoid overwriting)
        $existing_title = get_post_meta($post_id, '_rank_math_title', true);
        $existing_yoast_title = get_post_meta($post_id, '_yoast_wpseo_title', true);

        if (!empty($existing_title) || !empty($existing_yoast_title)) {
            return; // Don't overwrite existing SEO data
        }

        $seo_data = $this->call_openai_api($post_id, $post, $api_key);

        if ($seo_data) {
            // Validate with Google Keyword Planner if enabled
            if (get_option('keseo_enable_google_validation', '0') === '1') {
                $seo_data = $this->validate_with_google($seo_data);
            }

            $this->update_seo_meta($post_id, $seo_data);
            $this->log_success('SEO data generated successfully', $post_id);
        } else {
            $this->log_error('Failed to generate SEO data', $post_id);
        }
    }

    private function call_openai_api($post_id, $post, $api_key) {
        // Check cache first
        $cache_key = 'keseo_seo_' . md5($post_id . $post->post_modified);
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false) {
            return $cached_data;
        }

        $title = get_the_title($post_id);
        $content = wp_strip_all_tags($post->post_content);
        $excerpt = get_the_excerpt($post_id);
        
        // Get post-specific keywords or fall back to global settings
        $focus_keyword = get_post_meta($post_id, '_keseo_focus_keyword', true);
        $focused_seo_words = get_post_meta($post_id, '_keseo_focused_seo_words', true);
        $longtail_keywords = get_post_meta($post_id, '_keseo_longtail_keywords', true);
        $target_audience = get_post_meta($post_id, '_keseo_target_audience', true);
        $seo_intent = get_post_meta($post_id, '_keseo_seo_intent', true);
        
        // Fallback to global settings if individual fields are empty
        if (empty($focused_seo_words)) {
            $focused_seo_words = get_option('keseo_focus_keywords', '');
        }

        // Enhanced prompt with validation mechanisms
        $business_context = "lubricants, automotive oils, industrial fluids, and related automotive products";
        $market_context = $this->get_market_context();

        // Build targeted prompt based on individual post settings
        $intent_context = !empty($seo_intent) ? "SEO Intent: {$seo_intent}" : "SEO Intent: informational";
        $audience_context = !empty($target_audience) ? "Target Audience: {$target_audience}" : "Target Audience: general automotive consumers";
        
        $prompt = "You are a senior SEO specialist with expertise in {$business_context}. Using current market data: {$market_context}
        
        SPECIFIC PAGE TARGETING:
        {$intent_context}
        {$audience_context}
        Primary Focus Keyword: {$focus_keyword}
        Focused SEO Words: {$focused_seo_words}
        Long-tail Keywords: {$longtail_keywords}
        
        IMPORTANT: Base keyword selection on these priority factors:
        1. The specific keywords provided for this page
        2. User search intent (" . (!empty($seo_intent) ? $seo_intent : 'informational') . ")
        3. Target audience needs and language
        4. Commercial intent and conversion potential
        5. Long-tail keyword opportunities
        
        Analyze the following content and generate highly targeted SEO data:
CONTENT ANALYSIS:
Title: {$title}
Content: " . substr($content, 0, 1500) . "
Excerpt: {$excerpt}

TARGETING REQUIREMENTS:
1. SEO Title (50-60 chars): Use the primary focus keyword '{$focus_keyword}' if provided, optimize for {$intent_context}
2. Meta Description (150-155 chars): Include primary keyword, target {$audience_context}, add compelling CTA
3. Primary Focus Keyword: Use provided '{$focus_keyword}' or select best from focused SEO words
4. SEO Tags (5-8 tags): Prioritize the focused SEO words and long-tail keywords provided
5. Image Alt Text: Include primary keyword, be descriptive and accessible
6. Schema Type: Choose based on content type and SEO intent
7. Open Graph Title: Social media optimized for target audience
8. Open Graph Description: Social sharing optimized, audience-specific

KEYWORD STRATEGY: Heavily prioritize the provided focused SEO words and long-tail keywords for this specific page. Create content that targets the exact audience and intent specified.

OUTPUT FORMAT: Return ONLY valid JSON with exact keys: meta_title, meta_description, focus_keyword, seo_tags, image_alt_text, schema_type, og_title, og_description";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4',
                'messages' => array(
                    array('role' => 'user', 'content' => $prompt)
                ),
                'temperature' => 0.3,
                'max_tokens' => 1000
            )),
            'timeout' => 120
        ));

        if (is_wp_error($response)) {
            $this->log_error('OpenAI API Error: ' . $response->get_error_message(), $post_id);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['choices']) || !isset($data['choices'][0]) || !isset($data['choices'][0]['message']) || !isset($data['choices'][0]['message']['content'])) {
            $this->log_error('Invalid OpenAI API response', $post_id);
            return false;
        }

        $seo_content = $data['choices'][0]['message']['content'];

        // Clean the JSON response (remove markdown formatting if present)
        $seo_content = preg_replace('/```json\s*/', '', $seo_content);
        $seo_content = preg_replace('/```\s*$/', '', $seo_content);

        $seo_data = json_decode($seo_content, true);

        if (!$seo_data) {
            $this->log_error('Failed to parse OpenAI JSON response', $post_id);
            return false;
        }

        // Cache the result for 24 hours
        set_transient($cache_key, $seo_data, 24 * HOUR_IN_SECONDS);

        return $seo_data;
    }

    private function get_market_context() {
        // Get current trends and market data for better keyword selection
        $context = array();

        // Get seasonal trends
        $month = date('n');
        if (in_array($month, [3, 4, 5])) {
            $context[] = "Spring maintenance season - higher demand for oil changes and fluid checks";
        } elseif (in_array($month, [6, 7, 8])) {
            $context[] = "Summer driving season - focus on high-temperature performance";
        } elseif (in_array($month, [9, 10, 11])) {
            $context[] = "Fall preparation season - winterization and maintenance focus";
        } else {
            $context[] = "Winter season - cold weather performance and protection emphasis";
        }

        // Get regional considerations
        $timezone = get_option('timezone_string');
        if (strpos($timezone, 'America') !== false) {
            $context[] = "North American market - emphasis on automotive and industrial applications";
        }

        // Get business focus from settings
        $focus_keywords = get_option('keseo_focus_keywords', '');
        if (!empty($focus_keywords)) {
            $context[] = "Business focus areas: " . $focus_keywords;
        }

        return implode('. ', $context);
    }

    private function validate_with_google($seo_data) {
        if (!$this->google_api) {
            return $seo_data; // Return original if Google API not available
        }

        // Extract keywords for validation
        $keywords_to_validate = array();

        if (!empty($seo_data['focus_keyword'])) {
            $keywords_to_validate[] = $seo_data['focus_keyword'];
        }

        if (!empty($seo_data['seo_tags'])) {
            $tags = array_map('trim', explode(',', $seo_data['seo_tags']));
            $keywords_to_validate = array_merge($keywords_to_validate, array_slice($tags, 0, 5));
        }

        if (empty($keywords_to_validate)) {
            return $seo_data;
        }

        // Get Google validation data
        $google_data = $this->google_api->get_keyword_data($keywords_to_validate);

        if (!$google_data) {
            return $seo_data; // Return original if validation fails
        }

        // Find the best keyword based on Google data
        $best_keyword = $this->select_best_keyword($google_data);

        if ($best_keyword) {
            // Update focus keyword with best performing option
            $seo_data['focus_keyword'] = $best_keyword['keyword'];

            // Add Google metrics to the response
            $seo_data['google_validation'] = array(
                'search_volume' => $best_keyword['search_volume'],
                'competition' => $best_keyword['competition'],
                'avg_cpc' => $best_keyword['avg_cpc'],
                'opportunity_score' => $best_keyword['opportunity_score'],
                'validation_status' => 'validated'
            );

            // Update tags to prioritize high-opportunity keywords
            $validated_tags = array();
            foreach ($google_data as $keyword => $data) {
                if ($data['opportunity_score'] > 30) {
                    $validated_tags[] = $keyword;
                }
            }

            if (!empty($validated_tags)) {
                $seo_data['seo_tags'] = implode(', ', array_slice($validated_tags, 0, 8));
            }
        }

        return $seo_data;
    }

    private function select_best_keyword($google_data) {
        if (empty($google_data)) {
            return null;
        }

        $best_keyword = null;
        $best_score = 0;

        foreach ($google_data as $keyword => $data) {
            // Calculate weighted score
            $volume_score = min($data['search_volume'] / 100, 50); // Normalize volume
            $opportunity_score = $data['opportunity_score'];
            $competition_penalty = $data['competition_score'] * 0.3;

            $total_score = $volume_score + $opportunity_score - $competition_penalty;

            if ($total_score > $best_score && $data['search_volume'] >= 10) {
                $best_score = $total_score;
                $best_keyword = $data;
            }
        }

        return $best_keyword;
    }

    private function update_seo_meta($post_id, $seo_data) {
        // Include the plugin functions if not already loaded
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        // Update Rank Math fields
        if (is_plugin_active('seo-by-rank-math/rank-math.php')) {
            update_post_meta($post_id, 'rank_math_title', sanitize_text_field(isset($seo_data['meta_title']) ? $seo_data['meta_title'] : ''));
            update_post_meta($post_id, 'rank_math_description', sanitize_textarea_field(isset($seo_data['meta_description']) ? $seo_data['meta_description'] : ''));
            update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field(isset($seo_data['focus_keyword']) ? $seo_data['focus_keyword'] : ''));
        }

        // Update Yoast fields
        if (is_plugin_active('wordpress-seo/wp-seo.php')) {
            update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field(isset($seo_data['meta_title']) ? $seo_data['meta_title'] : ''));
            update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field(isset($seo_data['meta_description']) ? $seo_data['meta_description'] : ''));
            update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field(isset($seo_data['focus_keyword']) ? $seo_data['focus_keyword'] : ''));
        }

        // Update our own fields for fallback
        update_post_meta($post_id, '_keseo_title', sanitize_text_field(isset($seo_data['meta_title']) ? $seo_data['meta_title'] : ''));
        update_post_meta($post_id, '_keseo_description', sanitize_textarea_field(isset($seo_data['meta_description']) ? $seo_data['meta_description'] : ''));
        update_post_meta($post_id, '_keseo_focus_keyword', sanitize_text_field(isset($seo_data['focus_keyword']) ? $seo_data['focus_keyword'] : ''));
        update_post_meta($post_id, '_keseo_schema_type', sanitize_text_field(isset($seo_data['schema_type']) ? $seo_data['schema_type'] : 'Article'));
        update_post_meta($post_id, '_keseo_og_title', sanitize_text_field(isset($seo_data['og_title']) ? $seo_data['og_title'] : ''));
        update_post_meta($post_id, '_keseo_og_description', sanitize_textarea_field(isset($seo_data['og_description']) ? $seo_data['og_description'] : ''));

        // Set post tags
        if (!empty($seo_data['seo_tags'])) {
            $tags = array_map('trim', explode(',', $seo_data['seo_tags']));
            wp_set_post_terms($post_id, $tags, 'post_tag', false);
        }

        // Update featured image alt text
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id && !empty($seo_data['image_alt_text'])) {
            update_post_meta($thumbnail_id, '_wp_attachment_image_alt', sanitize_text_field($seo_data['image_alt_text']));
        }

        // Update all attached images alt text
        $attachments = get_attached_media('image', $post_id);
        foreach ($attachments as $attachment) {
            $existing_alt = get_post_meta($attachment->ID, '_wp_attachment_image_alt', true);
            if (empty($existing_alt) && !empty($seo_data['image_alt_text'])) {
                update_post_meta($attachment->ID, '_wp_attachment_image_alt', sanitize_text_field($seo_data['image_alt_text']));
            }
        }
    }

    public function output_schema_markup() {
        if (!get_option('keseo_enable_schema', '1') || !is_singular()) return;

        global $post;
        $schema_type = get_post_meta($post->ID, '_keseo_schema_type', true);

        if (empty($schema_type)) $schema_type = 'Article';

        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => $schema_type,
            'headline' => get_the_title(),
            'description' => get_the_excerpt() ?: get_post_meta($post->ID, '_keseo_description', true),
            'url' => get_permalink(),
            'datePublished' => get_the_date('c'),
            'dateModified' => get_the_modified_date('c'),
            'author' => array(
                '@type' => 'Person',
                'name' => get_the_author()
            )
        );

        // Add organization for products
        if ($schema_type === 'Product') {
            $schema['brand'] = array(
                '@type' => 'Brand',
                'name' => get_bloginfo('name')
            );
        }

        // Add featured image
        if (has_post_thumbnail()) {
            $image = wp_get_attachment_image_src(get_post_thumbnail_id(), 'large');
            $schema['image'] = $image[0];
        }

        echo '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    public function output_open_graph_tags() {
        if (!get_option('keseo_enable_og_tags', '1') || !is_singular()) return;

        global $post;

        $og_title = get_post_meta($post->ID, '_keseo_og_title', true) ?: get_the_title();
        $og_description = get_post_meta($post->ID, '_keseo_og_description', true) ?: get_the_excerpt();

        echo '<meta property="og:title" content="' . esc_attr($og_title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($og_description) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url(get_permalink()) . '">' . "\n";
        echo '<meta property="og:type" content="article">' . "\n";

        if (has_post_thumbnail()) {
            $image = wp_get_attachment_image_src(get_post_thumbnail_id(), 'large');
            echo '<meta property="og:image" content="' . esc_url($image[0]) . '">' . "\n";

            $alt_text = get_post_meta(get_post_thumbnail_id(), '_wp_attachment_image_alt', true);
            if ($alt_text) {
                echo '<meta property="og:image:alt" content="' . esc_attr($alt_text) . '">' . "\n";
            }
        }
    }

    public function enhance_sitemap_entry($entry, $post, $post_type) {
        // Add lastmod date for better indexing
        $entry['lastmod'] = get_the_modified_date('c', $post);

        // Add priority based on post type and recency
        if ($post_type === 'product') {
            $entry['priority'] = '0.8';
        } elseif ($post_type === 'post') {
            // Higher priority for recent posts
            $days_old = (time() - strtotime($post->post_date)) / (60 * 60 * 24);
            $entry['priority'] = $days_old < 30 ? '0.8' : '0.6';
        }

        return $entry;
    }

    // Save custom meta fields
    private function save_custom_meta_fields($post_id) {
        // Verify nonce
        if (!isset($_POST['keseo_meta_box_nonce']) || 
            !wp_verify_nonce($_POST['keseo_meta_box_nonce'], 'keseo_meta_box')) {
            return;
        }

        // Check user permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save all custom fields
        $fields = array(
            'keseo_title' => '_keseo_title',
            'keseo_description' => '_keseo_description', 
            'keseo_focus_keyword' => '_keseo_focus_keyword',
            'keseo_focused_seo_words' => '_keseo_focused_seo_words',
            'keseo_longtail_keywords' => '_keseo_longtail_keywords',
            'keseo_target_audience' => '_keseo_target_audience',
            'keseo_seo_intent' => '_keseo_seo_intent',
            'keseo_target_location' => '_keseo_target_location',
            'keseo_custom_alt_text' => '_keseo_custom_alt_text'
        );

        foreach ($fields as $field => $meta_key) {
            if (isset($_POST[$field])) {
                $value = sanitize_textarea_field($_POST[$field]);
                $old_value = get_post_meta($post_id, $meta_key, true);
                
                // Track changes
                if ($old_value !== $value) {
                    update_post_meta($post_id, $meta_key, $value);
                    $this->log_seo_change($post_id, $meta_key, $old_value, $value);
                }
            }
        }
        
        // Apply custom alt text to images if set
        if (isset($_POST['keseo_custom_alt_text']) && !empty($_POST['keseo_custom_alt_text'])) {
            $alt_text = sanitize_textarea_field($_POST['keseo_custom_alt_text']);
            $updated_images = $this->apply_alt_text_to_images($post_id, $alt_text);
            if ($updated_images > 0) {
                $this->log_success("Applied alt text to $updated_images images", $post_id);
            }
        }
        
        // Update last modified timestamp
        update_post_meta($post_id, '_keseo_last_updated', current_time('mysql'));
    }

    // Log SEO changes for tracking
    private function log_seo_change($post_id, $field, $old_value, $new_value) {
        $changes = get_post_meta($post_id, '_keseo_change_log', true);
        if (!is_array($changes)) {
            $changes = array();
        }
        
        $change_entry = array(
            'field' => $field,
            'old_value' => $old_value,
            'new_value' => $new_value,
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id()
        );
        
        $changes[] = $change_entry;
        
        // Keep only last 10 changes to avoid bloating
        if (count($changes) > 10) {
            $changes = array_slice($changes, -10);
        }
        
        update_post_meta($post_id, '_keseo_change_log', $changes);
    }

    // Dashboard page
    public function dashboard_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        ?>
        <div class="wrap">
            <h1>SEO Dashboard</h1>
            
            <div class="keseo-dashboard">
                <div class="dashboard-stats">
                    <div class="stat-box">
                        <h3>📊 SEO Statistics</h3>
                        <div id="seo-stats-content">Loading...</div>
                    </div>
                    
                    <div class="stat-box">
                        <h3>🔄 Recent Changes</h3>
                        <div id="recent-changes-content">Loading...</div>
                    </div>
                </div>
                
                <div class="dashboard-table">
                    <h3>📋 All Posts SEO Status</h3>
                    <div class="tablenav top">
                        <div class="alignleft actions">
                            <select id="filter-post-type">
                                <option value="">All Post Types</option>
                                <option value="post">Posts</option>
                                <option value="page">Pages</option>
                                <option value="product">Products</option>
                            </select>
                            <select id="filter-seo-status">
                                <option value="">All SEO Status</option>
                                <option value="optimized">Optimized</option>
                                <option value="partial">Partial</option>
                                <option value="missing">Missing SEO</option>
                            </select>
                            <button type="button" id="apply-filters" class="button">Filter</button>
                        </div>
                        <div class="alignright">
                            <button type="button" id="export-seo-data" class="button">Export CSV</button>
                        </div>
                    </div>
                    <div id="seo-posts-table">Loading posts...</div>
                </div>
            </div>
        </div>

        <style>
        .keseo-dashboard { margin-top: 20px; }
        .dashboard-stats { display: flex; gap: 20px; margin-bottom: 30px; }
        .stat-box { flex: 1; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; }
        .stat-box h3 { margin-top: 0; color: #0073aa; }
        .dashboard-table { background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; }
        .seo-status-optimized { color: #46b450; font-weight: bold; }
        .seo-status-partial { color: #f56e28; font-weight: bold; }
        .seo-status-missing { color: #dc3232; font-weight: bold; }
        .tablenav { padding: 10px 0; }
        .tablenav .alignleft { float: left; }
        .tablenav .alignright { float: right; }
        .tablenav:after { content: ""; display: table; clear: both; }
        .posts-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .posts-table th, .posts-table td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #ddd; }
        .posts-table th { background: #f1f1f1; font-weight: 600; }
        .posts-table .row-actions { color: #666; font-size: 12px; }
        .posts-table .row-actions a { color: #0073aa; text-decoration: none; }
        .posts-table .row-actions a:hover { text-decoration: underline; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            loadDashboardData();
            
            $('#apply-filters').on('click', function() {
                loadPostsTable();
            });
            
            $('#export-seo-data').on('click', function() {
                exportSEOData();
            });
            
            function loadDashboardData() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'keseo_get_dashboard_stats',
                        nonce: '<?php echo wp_create_nonce('keseo_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#seo-stats-content').html(response.data.stats);
                            $('#recent-changes-content').html(response.data.changes);
                        }
                    }
                });
                loadPostsTable();
            }
            
            function loadPostsTable() {
                var postType = $('#filter-post-type').val();
                var seoStatus = $('#filter-seo-status').val();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'keseo_get_posts_table',
                        post_type: postType,
                        seo_status: seoStatus,
                        nonce: '<?php echo wp_create_nonce('keseo_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#seo-posts-table').html(response.data);
                        }
                    }
                });
            }
            
            function exportSEOData() {
                window.location.href = ajaxurl + '?action=keseo_export_csv&nonce=<?php echo wp_create_nonce('keseo_nonce'); ?>';
            }
        });
        </script>
        <?php
    }

    // AJAX handlers
    public function ajax_generate_preview() {
        check_ajax_referer('keseo_nonce', 'nonce');

        $post_id = intval($_POST['post_id']);
        $post = get_post($post_id);

        if (!$post) {
            wp_send_json_error('Invalid post ID');
        }

        $api_key = get_option('kelubricants_openai_api_key');
        if (empty($api_key)) {
            wp_send_json_error('OpenAI API key not configured');
        }

        $seo_data = $this->call_openai_api($post_id, $post, $api_key);

        if ($seo_data) {
            wp_send_json_success($seo_data);
        } else {
            wp_send_json_error('Failed to generate SEO preview');
        }
    }

    public function ajax_bulk_generate() {
        check_ajax_referer('keseo_nonce', 'nonce');

        $posts = get_posts(array(
            'post_type' => get_option('keseo_post_types', array('post', 'page')),
            'posts_per_page' => 50,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => '_keseo_title',
                    'compare' => 'NOT EXISTS'
                )
            )
        ));

        $generated = 0;
        $api_key = get_option('kelubricants_openai_api_key');

        if (empty($api_key)) {
            wp_send_json_error('OpenAI API key not configured');
        }

        foreach ($posts as $post) {
            $seo_data = $this->call_openai_api($post->ID, $post, $api_key);
            if ($seo_data) {
                $this->update_seo_meta($post->ID, $seo_data);
                $generated++;
            }

            // Add delay to avoid API rate limits
            sleep(1);
        }

        wp_send_json_success("Generated SEO data for {$generated} posts.");
    }

    public function ajax_test_api() {
        check_ajax_referer('keseo_nonce', 'nonce');

        $api_key = sanitize_text_field($_POST['api_key']);

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => 'gpt-3.5-turbo',
                'messages' => array(array('role' => 'user', 'content' => 'Test')),
                'max_tokens' => 5
            )),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            wp_send_json_error('Connection failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            wp_send_json_success('API key is valid');
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error = isset($body['error']['message']) ? $body['error']['message'] : 'Unknown error';
            wp_send_json_error($error);
        }
    }

    public function ajax_test_google_api() {
        check_ajax_referer('keseo_nonce', 'nonce');

        if (!$this->google_api) {
            wp_send_json_error('Google API integration not initialized');
            return;
        }

        $test_result = $this->google_api->test_connection();

        if ($test_result['success']) {
            wp_send_json_success($test_result['message']);
        } else {
            wp_send_json_error($test_result['message']);
        }
    }

    public function ajax_get_analysis() {
        check_ajax_referer('keseo_nonce', 'nonce');

        $posts_with_seo = get_posts(array(
            'post_type' => 'any',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_keseo_title',
                    'compare' => 'EXISTS'
                )
            )
        ));

        $total_posts = wp_count_posts()->publish + wp_count_posts('page')->publish;
        $posts_with_seo_count = count($posts_with_seo);
        $coverage_percent = $total_posts > 0 ? round(($posts_with_seo_count / $total_posts) * 100, 1) : 0;

        $html = '<div class="seo-analysis-widget">';
        $html .= '<h3>SEO Coverage Analysis</h3>';
        $html .= '<p><strong>Posts with SEO data:</strong> ' . $posts_with_seo_count . ' / ' . $total_posts . ' (' . $coverage_percent . '%)</p>';

        if ($coverage_percent < 100) {
            $missing = $total_posts - $posts_with_seo_count;
            $html .= '<p><em>' . $missing . ' posts still need SEO optimization.</em></p>';
            $html .= '<button type="button" id="generate-missing-seo" class="button button-primary">Generate SEO for Missing Posts</button>';
        } else {
            $html .= '<p style="color: green;">✓ All posts have SEO data!</p>';
        }

        $html .= '</div>';

        wp_send_json_success($html);
    }

    public function ajax_generate_targeted() {
        check_ajax_referer('keseo_nonce', 'nonce');

        $post_id = intval($_POST['post_id']);
        $post = get_post($post_id);

        if (!$post) {
            wp_send_json_error('Invalid post ID');
        }

        $api_key = get_option('kelubricants_openai_api_key');
        if (empty($api_key)) {
            wp_send_json_error('OpenAI API key not configured');
        }

        // Generate SEO with current post-specific keywords
        $seo_data = $this->call_openai_api($post_id, $post, $api_key);

        if ($seo_data) {
            // Add some additional info
            $seo_data['generation_type'] = 'targeted';
            $seo_data['keywords_used'] = array(
                'focus' => get_post_meta($post_id, '_keseo_focus_keyword', true),
                'focused_words' => get_post_meta($post_id, '_keseo_focused_seo_words', true),
                'longtail' => get_post_meta($post_id, '_keseo_longtail_keywords', true)
            );
            wp_send_json_success($seo_data);
        } else {
            wp_send_json_error('Failed to generate targeted SEO');
        }
    }

    public function ajax_generate_longtail() {
        check_ajax_referer('keseo_nonce', 'nonce');

        $post_id = intval($_POST['post_id']);
        $post = get_post($post_id);

        if (!$post) {
            wp_send_json_error('Invalid post ID');
        }

        $api_key = get_option('kelubricants_openai_api_key');
        if (empty($api_key)) {
            wp_send_json_error('OpenAI API key not configured');
        }

        // Generate long-tail focused SEO
        $seo_data = $this->generate_longtail_seo($post_id, $post, $api_key);

        if ($seo_data) {
            wp_send_json_success($seo_data);
        } else {
            wp_send_json_error('Failed to generate long-tail SEO');
        }
    }

    public function ajax_analyze_competition() {
        check_ajax_referer('keseo_nonce', 'nonce');

        $post_id = intval($_POST['post_id']);
        $post = get_post($post_id);

        if (!$post) {
            wp_send_json_error('Invalid post ID');
        }

        // Get the focus keyword for analysis
        $focus_keyword = get_post_meta($post_id, '_keseo_focus_keyword', true);
        if (empty($focus_keyword)) {
            wp_send_json_error('Please set a focus keyword first');
        }

        // Perform competition analysis
        $analysis = $this->analyze_keyword_competition($focus_keyword);

        wp_send_json_success($analysis);
    }

    private function generate_longtail_seo($post_id, $post, $api_key) {
        $title = get_the_title($post_id);
        $content = wp_strip_all_tags($post->post_content);
        $longtail_keywords = get_post_meta($post_id, '_keseo_longtail_keywords', true);
        $target_audience = get_post_meta($post_id, '_keseo_target_audience', true);

        if (empty($longtail_keywords)) {
            return false;
        }

        $prompt = "You are an expert in long-tail keyword SEO optimization. Focus on creating content that targets specific, longer search phrases.

TARGET LONG-TAIL KEYWORDS: {$longtail_keywords}
TARGET AUDIENCE: {$target_audience}
CONTENT TITLE: {$title}
CONTENT PREVIEW: " . substr($content, 0, 800) . "

LONG-TAIL STRATEGY:
Create SEO elements that specifically target the long-tail keywords provided. These should capture users who are further along in their research journey and have specific needs.

REQUIREMENTS:
1. SEO Title (50-60 chars): Include 1-2 long-tail keywords naturally
2. Meta Description (150-155 chars): Target a specific long-tail query with clear value proposition
3. Focus Keyword: Select the most promising long-tail keyword from the list
4. SEO Tags: Use variations of the long-tail keywords
5. Schema Type: Choose based on long-tail intent (usually informational)

OPTIMIZATION STRATEGY: 
- Target question-based queries (how to, what is, why does)
- Include specific product/service mentions
- Address pain points and specific use cases
- Use conversational, natural language

OUTPUT FORMAT: Return ONLY valid JSON with exact keys: meta_title, meta_description, focus_keyword, seo_tags, schema_type, strategy_notes";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4',
                'messages' => array(
                    array('role' => 'user', 'content' => $prompt)
                ),
                'temperature' => 0.3,
                'max_tokens' => 1000
            )),
            'timeout' => 120
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['choices']) || !isset($data['choices'][0]) || !isset($data['choices'][0]['message']) || !isset($data['choices'][0]['message']['content'])) {
            return false;
        }

        $seo_content = $data['choices'][0]['message']['content'];
        $seo_content = preg_replace('/```json\s*/', '', $seo_content);
        $seo_content = preg_replace('/```\s*$/', '', $seo_content);

        return json_decode($seo_content, true);
    }

    private function analyze_keyword_competition($keyword) {
        // Simple competition analysis based on keyword characteristics
        $analysis = array(
            'keyword' => $keyword,
            'difficulty_score' => 0,
            'suggestions' => array(),
            'opportunities' => array()
        );

        // Basic keyword analysis
        $word_count = str_word_count($keyword);
        $length = strlen($keyword);

        // Calculate difficulty score (lower is better)
        if ($word_count >= 4) {
            $analysis['difficulty_score'] = 30; // Long-tail, easier
            $analysis['suggestions'][] = "Great long-tail keyword - lower competition expected";
        } elseif ($word_count == 3) {
            $analysis['difficulty_score'] = 50; // Medium tail
            $analysis['suggestions'][] = "Medium-tail keyword - moderate competition";
        } else {
            $analysis['difficulty_score'] = 80; // Short tail, harder
            $analysis['suggestions'][] = "Short keyword - high competition expected";
        }

        // Check for commercial intent
        $commercial_words = array('buy', 'price', 'cost', 'cheap', 'best', 'review', 'compare');
        $has_commercial = false;
        foreach ($commercial_words as $word) {
            if (strpos(strtolower($keyword), $word) !== false) {
                $has_commercial = true;
                break;
            }
        }

        if ($has_commercial) {
            $analysis['difficulty_score'] += 20;
            $analysis['suggestions'][] = "Commercial keyword - higher competition but better conversion potential";
        }

        // Generate opportunities
        $analysis['opportunities'][] = "Target variation: '{$keyword} guide'";
        $analysis['opportunities'][] = "Target variation: 'best {$keyword}'";
        $analysis['opportunities'][] = "Target variation: 'how to choose {$keyword}'";

        // Set difficulty level
        if ($analysis['difficulty_score'] <= 40) {
            $analysis['difficulty_level'] = 'Easy';
            $analysis['color'] = 'green';
        } elseif ($analysis['difficulty_score'] <= 70) {
            $analysis['difficulty_level'] = 'Medium';
            $analysis['color'] = 'orange';
        } else {
            $analysis['difficulty_level'] = 'Hard';
            $analysis['color'] = 'red';
        }

        return $analysis;
    }

    public function ajax_get_dashboard_stats() {
        check_ajax_referer('keseo_nonce', 'nonce');

        // Get SEO statistics
        $total_posts = wp_count_posts()->publish + wp_count_posts('page')->publish;
        
        $posts_with_seo = get_posts(array(
            'post_type' => array('post', 'page', 'product'),
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_keseo_title',
                    'compare' => 'EXISTS'
                )
            )
        ));

        $optimized_count = count($posts_with_seo);
        $optimization_rate = $total_posts > 0 ? round(($optimized_count / $total_posts) * 100, 1) : 0;

        // Recent changes
        $recent_changes = $this->get_recent_seo_changes(10);

        $stats_html = "<div class='dashboard-stat'>
            <span class='stat-number'>{$optimized_count}/{$total_posts}</span>
            <span class='stat-label'>Posts Optimized</span>
            <div class='stat-bar'>
                <div class='stat-fill' style='width: {$optimization_rate}%'></div>
            </div>
            <span class='stat-percent'>{$optimization_rate}%</span>
        </div>";

        $changes_html = "<div class='recent-changes-list'>";
        if (empty($recent_changes)) {
            $changes_html .= "<p>No recent changes</p>";
        } else {
            foreach (array_slice($recent_changes, 0, 5) as $change) {
                $post_title = get_the_title($change['post_id']);
                $user = get_userdata($change['user_id']);
                $username = $user ? $user->display_name : 'Unknown';
                $time_ago = human_time_diff(strtotime($change['timestamp']), current_time('timestamp')) . ' ago';
                
                $changes_html .= "<div class='change-item'>
                    <strong>{$post_title}</strong> - {$change['field_label']} 
                    <small>by {$username}, {$time_ago}</small>
                </div>";
            }
        }
        $changes_html .= "</div>";

        wp_send_json_success(array(
            'stats' => $stats_html,
            'changes' => $changes_html
        ));
    }

    public function ajax_get_posts_table() {
        check_ajax_referer('keseo_nonce', 'nonce');

        $post_type = sanitize_text_field(isset($_POST['post_type']) ? $_POST['post_type'] : '');
        $seo_status = sanitize_text_field(isset($_POST['seo_status']) ? $_POST['seo_status'] : '');

        $args = array(
            'post_type' => !empty($post_type) ? $post_type : array('post', 'page', 'product'),
            'posts_per_page' => 50,
            'orderby' => 'modified',
            'order' => 'DESC'
        );

        // Filter by SEO status
        if ($seo_status === 'optimized') {
            $args['meta_query'] = array(
                array(
                    'key' => '_keseo_title',
                    'compare' => 'EXISTS'
                )
            );
        } elseif ($seo_status === 'missing') {
            $args['meta_query'] = array(
                array(
                    'key' => '_keseo_title',
                    'compare' => 'NOT EXISTS'
                )
            );
        }

        $posts = get_posts($args);

        $html = "<table class='posts-table'>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Type</th>
                    <th>SEO Status</th>
                    <th>Focus Keyword</th>
                    <th>Last Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>";

        foreach ($posts as $post) {
            $seo_title = get_post_meta($post->ID, '_keseo_title', true);
            $seo_description = get_post_meta($post->ID, '_keseo_description', true);
            $focus_keyword = get_post_meta($post->ID, '_keseo_focus_keyword', true);
            $last_updated = get_post_meta($post->ID, '_keseo_last_updated', true);
            
            // Determine SEO status
            if (!empty($seo_title) && !empty($seo_description) && !empty($focus_keyword)) {
                $status = '<span class="seo-status-optimized">✓ Optimized</span>';
            } elseif (!empty($seo_title) || !empty($seo_description)) {
                $status = '<span class="seo-status-partial">⚠ Partial</span>';
            } else {
                $status = '<span class="seo-status-missing">✗ Missing</span>';
            }

            $edit_link = get_edit_post_link($post->ID);
            $view_link = get_permalink($post->ID);
            
            $updated_display = $last_updated ? 
                human_time_diff(strtotime($last_updated), current_time('timestamp')) . ' ago' : 
                'Never';

            $html .= "<tr>
                <td><strong>{$post->post_title}</strong></td>
                <td>" . ucfirst($post->post_type) . "</td>
                <td>{$status}</td>
                <td>" . ($focus_keyword ?: '-') . "</td>
                <td>{$updated_display}</td>
                <td>
                    <div class='row-actions'>
                        <a href='{$edit_link}'>Edit</a> |
                        <a href='{$view_link}' target='_blank'>View</a>
                    </div>
                </td>
            </tr>";
        }

        $html .= "</tbody></table>";

        wp_send_json_success($html);
    }

    public function ajax_export_csv() {
        check_ajax_referer('keseo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $posts = get_posts(array(
            'post_type' => array('post', 'page', 'product'),
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ));

        $filename = 'seo-data-' . date('Y-m-d') . '.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // CSV headers
        fputcsv($output, array(
            'ID',
            'Title', 
            'Type',
            'URL',
            'SEO Title',
            'Meta Description',
            'Focus Keyword',
            'Focused SEO Words',
            'Long-tail Keywords',
            'Target Audience',
            'SEO Intent',
            'Last Updated'
        ));

        foreach ($posts as $post) {
            fputcsv($output, array(
                $post->ID,
                $post->post_title,
                $post->post_type,
                get_permalink($post->ID),
                get_post_meta($post->ID, '_keseo_title', true),
                get_post_meta($post->ID, '_keseo_description', true),
                get_post_meta($post->ID, '_keseo_focus_keyword', true),
                get_post_meta($post->ID, '_keseo_focused_seo_words', true),
                get_post_meta($post->ID, '_keseo_longtail_keywords', true),
                get_post_meta($post->ID, '_keseo_target_audience', true),
                get_post_meta($post->ID, '_keseo_seo_intent', true),
                get_post_meta($post->ID, '_keseo_last_updated', true)
            ));
        }
        
        fclose($output);
        exit;
    }

    private function get_recent_seo_changes($limit = 10) {
        global $wpdb;
        
        $changes = array();
        
        // Get posts with change logs
        $posts = get_posts(array(
            'post_type' => array('post', 'page', 'product'),
            'posts_per_page' => 50,
            'meta_query' => array(
                array(
                    'key' => '_keseo_change_log',
                    'compare' => 'EXISTS'
                )
            )
        ));

        foreach ($posts as $post) {
            $post_changes = get_post_meta($post->ID, '_keseo_change_log', true);
            if (is_array($post_changes)) {
                foreach ($post_changes as $change) {
                    $change['post_id'] = $post->ID;
                    $change['field_label'] = $this->get_field_label($change['field']);
                    $changes[] = $change;
                }
            }
        }

        // Sort by timestamp
        usort($changes, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        return array_slice($changes, 0, $limit);
    }

    private function get_field_label($field) {
        $labels = array(
            '_keseo_title' => 'SEO Title',
            '_keseo_description' => 'Meta Description',
            '_keseo_focus_keyword' => 'Focus Keyword',
            '_keseo_focused_seo_words' => 'Focused SEO Words',
            '_keseo_longtail_keywords' => 'Long-tail Keywords',
            '_keseo_target_audience' => 'Target Audience',
            '_keseo_seo_intent' => 'SEO Intent'
        );
        
        return isset($labels[$field]) ? $labels[$field] : $field;
    }

    public function ajax_auto_optimize() {
        check_ajax_referer('keseo_nonce', 'nonce');

        $post_id = intval($_POST['post_id']);
        $keyword = sanitize_text_field($_POST['keyword']);
        $location = sanitize_text_field($_POST['location']);

        if (!$post_id || !$keyword) {
            wp_send_json_error('Invalid post ID or keyword');
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Insufficient permissions');
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error('Post not found');
        }

        $api_key = get_option('kelubricants_openai_api_key');
        if (empty($api_key)) {
            wp_send_json_error('OpenAI API key not configured');
        }

        // Generate optimized SEO using improved prompt with location
        $optimized_seo = $this->generate_location_based_seo($post, $keyword, $location, $api_key);

        if ($optimized_seo) {
            wp_send_json_success($optimized_seo);
        } else {
            wp_send_json_error('Failed to generate optimized SEO');
        }
    }

    private function generate_location_based_seo($post, $keyword, $location, $api_key) {
        $content = wp_strip_all_tags($post->post_content);
        $excerpt = $post->post_excerpt ?: wp_trim_words($content, 30);
        
        // Enhanced prompt for location-based SEO
        $location_part = $location ? " in $location" : "";
        $prompt = "Write SEO for a B2B industrial product page targeting '$keyword'$location_part.

Product: {$post->post_title}
Content: " . wp_trim_words($content, 100) . "

Requirements:
- 60-character SEO title emphasizing quality, reliability, B2B use
- 150-character meta description with location and benefits
- Alt text for product images including location and keywords

Output ONLY in this exact format:
TITLE: [your 60-char title]
DESCRIPTION: [your 150-char description]  
ALT_TEXT: [your image alt text]";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4',
                'messages' => array(
                    array(
                        'role' => 'system',
                        'content' => 'You are an expert B2B SEO specialist for industrial lubricants and automotive products.'
                    ),
                    array(
                        'role' => 'user', 
                        'content' => $prompt
                    )
                ),
                'max_tokens' => 500,
                'temperature' => 0.7
            )),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            $this->log_error('OpenAI API request failed: ' . $response->get_error_message(), $post->ID);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!isset($data['choices']) || !isset($data['choices'][0]) || !isset($data['choices'][0]['message']) || !isset($data['choices'][0]['message']['content'])) {
            $this->log_error('Invalid OpenAI API response', $post->ID);
            return false;
        }

        $content = $data['choices'][0]['message']['content'];
        
        // Parse the structured output
        $result = array();
        
        if (preg_match('/TITLE:\s*(.+?)(?=\n|DESCRIPTION:|ALT_TEXT:|$)/i', $content, $matches)) {
            $result['title'] = trim($matches[1]);
        }
        
        if (preg_match('/DESCRIPTION:\s*(.+?)(?=\n|TITLE:|ALT_TEXT:|$)/i', $content, $matches)) {
            $result['description'] = trim($matches[1]);
        }
        
        if (preg_match('/ALT_TEXT:\s*(.+?)(?=\n|TITLE:|DESCRIPTION:|$)/i', $content, $matches)) {
            $result['alt_text'] = trim($matches[1]);
        }

        $this->log_success('Auto-optimization completed for post: ' . $post->post_title, $post->ID);
        return $result;
    }

    // Apply custom alt text to all images in post content and featured image
    private function apply_alt_text_to_images($post_id, $alt_text) {
        if (empty($alt_text)) {
            return 0;
        }

        $updated_count = 0;
        
        // Update featured image
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            update_post_meta($thumbnail_id, '_wp_attachment_image_alt', $alt_text);
            $updated_count++;
        }

        // Update all attached images
        $images = get_attached_media('image', $post_id);
        foreach ($images as $image) {
            update_post_meta($image->ID, '_wp_attachment_image_alt', $alt_text);
            $updated_count++;
        }

        return $updated_count;
    }

    public function ajax_bulk_preview() {
        check_ajax_referer('keseo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $post_types = isset($_POST['post_types']) ? $_POST['post_types'] : array('post', 'page', 'product');
        $mode = sanitize_text_field(isset($_POST['mode']) ? $_POST['mode'] : 'missing');

        $args = array(
            'post_type' => $post_types,
            'posts_per_page' => 50,
            'post_status' => 'publish'
        );

        // Filter based on mode
        if ($mode === 'missing') {
            $args['meta_query'] = array(
                'relation' => 'OR',
                array(
                    'key' => '_keseo_title',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key' => '_keseo_description', 
                    'compare' => 'NOT EXISTS'
                )
            );
        } elseif ($mode === 'partial') {
            $args['meta_query'] = array(
                'relation' => 'AND',
                array(
                    'relation' => 'OR',
                    array(
                        'key' => '_keseo_title',
                        'value' => '',
                        'compare' => '='
                    ),
                    array(
                        'key' => '_keseo_description',
                        'value' => '',
                        'compare' => '='
                    )
                ),
                array(
                    'relation' => 'OR',
                    array(
                        'key' => '_keseo_title',
                        'compare' => 'EXISTS'
                    ),
                    array(
                        'key' => '_keseo_description',
                        'compare' => 'EXISTS'
                    )
                )
            );
        }

        $posts = get_posts($args);
        $preview_data = array();

        foreach ($posts as $post) {
            $title = get_post_meta($post->ID, '_keseo_title', true);
            $description = get_post_meta($post->ID, '_keseo_description', true);
            $keyword = get_post_meta($post->ID, '_keseo_focus_keyword', true);

            $status = 'missing';
            if (!empty($title) && !empty($description) && !empty($keyword)) {
                $status = 'complete';
            } elseif (!empty($title) || !empty($description)) {
                $status = 'partial';
            }

            $preview_data[] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'type' => $post->post_type,
                'status' => $status,
                'current_seo_title' => $title,
                'current_description' => $description,
                'current_keyword' => $keyword
            );
        }

        wp_send_json_success(array(
            'posts' => $preview_data,
            'total' => count($preview_data)
        ));
    }

    public function ajax_bulk_generate() {
        check_ajax_referer('keseo_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $post_types = isset($_POST['post_types']) ? $_POST['post_types'] : array('post', 'page', 'product');
        $mode = sanitize_text_field(isset($_POST['mode']) ? $_POST['mode'] : 'missing');
        $base_keywords = sanitize_text_field(isset($_POST['base_keywords']) ? $_POST['base_keywords'] : '');
        $location = sanitize_text_field(isset($_POST['location']) ? $_POST['location'] : '');
        $batch_size = intval(isset($_POST['batch_size']) ? $_POST['batch_size'] : 5);
        $offset = intval(isset($_POST['offset']) ? $_POST['offset'] : 0);

        $api_key = get_option('kelubricants_openai_api_key');
        if (empty($api_key)) {
            wp_send_json_error('OpenAI API key not configured');
        }

        $args = array(
            'post_type' => $post_types,
            'posts_per_page' => $batch_size,
            'offset' => $offset,
            'post_status' => 'publish'
        );

        // Apply same filtering as preview
        if ($mode === 'missing') {
            $args['meta_query'] = array(
                'relation' => 'OR',
                array(
                    'key' => '_keseo_title',
                    'compare' => 'NOT EXISTS'
                ),
                array(
                    'key' => '_keseo_description',
                    'compare' => 'NOT EXISTS'
                )
            );
        } elseif ($mode === 'partial') {
            $args['meta_query'] = array(
                'relation' => 'AND',
                array(
                    'relation' => 'OR',
                    array(
                        'key' => '_keseo_title',
                        'value' => '',
                        'compare' => '='
                    ),
                    array(
                        'key' => '_keseo_description',
                        'value' => '',
                        'compare' => '='
                    )
                ),
                array(
                    'relation' => 'OR',
                    array(
                        'key' => '_keseo_title',
                        'compare' => 'EXISTS'
                    ),
                    array(
                        'key' => '_keseo_description',
                        'compare' => 'EXISTS'
                    )
                )
            );
        }

        $posts = get_posts($args);
        $results = array();
        $success_count = 0;
        $error_count = 0;

        foreach ($posts as $post) {
            // Get existing or default keyword
            $post_keyword = get_post_meta($post->ID, '_keseo_focus_keyword', true);
            $final_keyword = $post_keyword ?: $base_keywords ?: $post->post_title;

            // Generate SEO
            $seo_data = $this->generate_location_based_seo($post, $final_keyword, $location, $api_key);

            if ($seo_data) {
                // Save generated SEO
                if (!empty($seo_data['title'])) {
                    update_post_meta($post->ID, '_keseo_title', $seo_data['title']);
                }
                if (!empty($seo_data['description'])) {
                    update_post_meta($post->ID, '_keseo_description', $seo_data['description']);
                }
                if (!empty($seo_data['alt_text'])) {
                    update_post_meta($post->ID, '_keseo_custom_alt_text', $seo_data['alt_text']);
                    $this->apply_alt_text_to_images($post->ID, $seo_data['alt_text']);
                }
                if (!$post_keyword && $final_keyword) {
                    update_post_meta($post->ID, '_keseo_focus_keyword', $final_keyword);
                }
                if ($location) {
                    update_post_meta($post->ID, '_keseo_target_location', $location);
                }

                update_post_meta($post->ID, '_keseo_last_updated', current_time('mysql'));

                $results[] = array(
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'status' => 'success',
                    'seo_title' => isset($seo_data['title']) ? $seo_data['title'] : '',
                    'description' => isset($seo_data['description']) ? $seo_data['description'] : ''
                );
                $success_count++;
            } else {
                $results[] = array(
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'status' => 'error',
                    'error' => 'Failed to generate SEO'
                );
                $error_count++;
            }

            // Small delay to avoid API rate limits
            usleep(200000); // 0.2 seconds
        }

        // Check if there are more posts to process
        $total_posts_query = get_posts(array_merge($args, array('posts_per_page' => -1, 'fields' => 'ids')));
        $total_posts = count($total_posts_query);
        $has_more = ($offset + $batch_size) < $total_posts;

        wp_send_json_success(array(
            'results' => $results,
            'success_count' => $success_count,
            'error_count' => $error_count,
            'processed' => $offset + count($posts),
            'total' => $total_posts,
            'has_more' => $has_more,
            'next_offset' => $offset + $batch_size
        ));
    }

    // Logging functions
    private function log_error($message, $post_id = null) {
        $log_message = 'KE SEO Booster Error: ' . $message;
        if ($post_id) {
            $log_message .= ' (Post ID: ' . $post_id . ')';
        }
        error_log($log_message);
    }

    private function log_success($message, $post_id = null) {
        $log_message = 'KE SEO Booster Success: ' . $message;
        if ($post_id) {
            $log_message .= ' (Post ID: ' . $post_id . ')';
        }
        error_log($log_message);
    }
}

// Initialize the plugin
new KELubricantsSEOBooster();

// Activation hook
register_activation_hook(__FILE__, 'keseo_activation');
function keseo_activation() {
    // Set default options
    add_option('keseo_auto_generate', '1');
    add_option('keseo_enable_schema', '1');
    add_option('keseo_enable_og_tags', '1');
    add_option('keseo_post_types', array('post', 'page', 'product'));
    add_option('keseo_focus_keywords', 'lubricants, automotive oil, engine oil, industrial lubricants');
    add_option('keseo_enable_google_validation', '0');
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'keseo_deactivation');
function keseo_deactivation() {
    // Clean up if needed
    wp_clear_scheduled_hook('keseo_daily_analysis');
}

