<?php
if (!defined('ABSPATH')) exit;
class TilesView_WooCommerce_Sync {

    private $option_name_key    = 'tilesview_app_key';
    private $option_name_secret = 'tilesview_app_secret';
    private $api_base_url       = 'https://tilesview.ai/Provider/webhooks/';

    public function __construct() {
        tilesview_log("Plugin initialized");
        
        // Admin
        add_action('admin_menu',  [$this, 'add_admin_menu']);
        add_action('admin_init',  [$this, 'register_settings']);

        // Product Sync - UPDATED TO FIX SKU/PRICE ISSUE
        add_action('woocommerce_new_product',    [$this, 'on_product_create'], 10, 1);
        add_action('woocommerce_update_product', [$this, 'on_product_update'], 10, 1);
        
        // Product Delete - UPDATED TO FIX DELETE ISSUES ✅
        // Hook into both trash and permanent delete events
        add_action('wp_trash_post',      [$this, 'on_product_trash'], 10, 1);
        add_action('before_delete_post', [$this, 'on_product_delete'], 10, 2);
        add_action('untrash_post',       [$this, 'on_product_restore'], 10, 1); // Optional: restore functionality

        // Category Sync
        add_action('created_product_cat', [$this, 'on_category_create'], 10, 2);
        add_action('edited_product_cat',  [$this, 'on_category_update'], 10, 2);
    }

    /** --------------
     * ADMIN UI: API key/settings (unchanged)
     */
    public function add_admin_menu() {
        add_menu_page(
            'TilesView Settings', 
            'TilesView', 
            'manage_options', 
            'tilesview-settings', 
            [$this, 'settings_page_html'], 
            'dashicons-admin-settings'
        );
    }

    public function register_settings() {
        register_setting('tilesview_settings_group', $this->option_name_key);
        register_setting('tilesview_settings_group', $this->option_name_secret);
    }

    public function settings_page_html() {
        ?>
        <div class="wrap">
            <h1>TilesView API Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('tilesview_settings_group');
                do_settings_sections('tilesview_settings_group');
                ?>
                <table class="form-table">
                    <tr>
                        <th>App Key</th>
                        <td>
                            <input type="text" name="<?php echo esc_attr($this->option_name_key); ?>" 
                                   value="<?php echo esc_attr(get_option($this->option_name_key)); ?>" 
                                   class="regular-text" placeholder="Enter your TilesView App Key"/>
                        </td>
                    </tr>
                    <tr>
                        <th>App Secret</th>
                        <td>
                            <input type="text" name="<?php echo esc_attr($this->option_name_secret); ?>" 
                                   value="<?php echo esc_attr(get_option($this->option_name_secret)); ?>" 
                                   class="regular-text" placeholder="Enter your TilesView App Secret"/>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <div style="margin-top: 30px; padding: 15px; background: #f1f1f1; border-radius: 5px;">
                <h3>Debug Information</h3>
                <p><strong>Debug Mode:</strong> <?php echo TILESVIEW_DEBUG ? 'Enabled' : 'Disabled'; ?></p>
                <p><strong>API Base URL:</strong> <?php echo esc_html($this->api_base_url); ?></p>
                <p><strong>Log Location:</strong> /wp-content/debug.log (if WP_DEBUG_LOG is enabled)</p>
            </div>
        </div>
        <?php
    }

    /** --------------
     * HELPER: API CALLS WITH FULL DEBUGGING (unchanged)
     */
    private function get_api_headers() {
        $headers = [
            'Content-Type' => 'application/json',
            'app_key'      => get_option($this->option_name_key),
            'app_secret'   => get_option($this->option_name_secret),
        ];
        
        tilesview_log("API Headers prepared", $headers);
        return $headers;
    }

    private function api_call($endpoint, $method = 'GET', $payload = null, $context = '') {
        $url = $this->api_base_url . ltrim($endpoint, '/');
        
        // Pre-call logging
        tilesview_log("=== API CALL START ===");
        tilesview_log("Context: " . $context);
        tilesview_log("URL: " . $url);
        tilesview_log("Method: " . $method);
        
        $headers = $this->get_api_headers();
        
        // Validate API credentials
        if (empty($headers['app_key']) || empty($headers['app_secret'])) {
            tilesview_log("ERROR: Missing API credentials");
            return false;
        }

        $args = [
            'headers' => $headers,
            'timeout' => 30,
            'method'  => $method,
            'blocking' => true,
            'sslverify' => true
        ];

        if ($payload !== null) {
            $json_payload = json_encode($payload);
            $args['body'] = $json_payload;
            tilesview_log("Request Payload", $payload);
            tilesview_log("JSON Payload: " . $json_payload);
        }

        tilesview_log("Request Args", [
            'url' => $url,
            'method' => $method,
            'headers' => $headers,
            'timeout' => $args['timeout']
        ]);

        // Make the API call
        $start_time = microtime(true);
        $response = wp_remote_request($url, $args);
        $end_time = microtime(true);
        $duration = round(($end_time - $start_time) * 1000, 2);

        tilesview_log("API call duration: " . $duration . "ms");

        // Handle errors
        if (is_wp_error($response)) {
            tilesview_log("WP Error occurred", [
                'error_code' => $response->get_error_code(),
                'error_message' => $response->get_error_message(),
                'error_data' => $response->get_error_data()
            ]);
            tilesview_log("=== API CALL END (ERROR) ===");
            return false;
        }

        // Get response details
        $http_code = wp_remote_retrieve_response_code($response);
        $response_headers = wp_remote_retrieve_headers($response);
        $raw_body = wp_remote_retrieve_body($response);
        $body = json_decode($raw_body, true);

        tilesview_log("Response HTTP Code: " . $http_code);
        tilesview_log("Response Headers", $response_headers->getAll());
        tilesview_log("Raw Response Body: " . $raw_body);
        tilesview_log("Parsed Response Body", $body);

        // Check for successful response
        if ($http_code === 200 || $http_code === 201) {
            tilesview_log("API call successful");
            
            // Handle different response structures
            if (isset($body['data']['success']) && $body['data']['success'] === true) {
                tilesview_log("Response indicates success");
                tilesview_log("=== API CALL END (SUCCESS) ===");
                return $body;
            } elseif (isset($body['success']) && $body['success'] === true) {
                tilesview_log("Response indicates success (alternate structure)");
                tilesview_log("=== API CALL END (SUCCESS) ===");
                return $body;
            } elseif (isset($body['tv_prod_id'])) {
                tilesview_log("Response contains tv_prod_id: " . $body['tv_prod_id']);
                tilesview_log("=== API CALL END (SUCCESS) ===");
                return $body;
            } else {
                tilesview_log("Response structure unclear, treating as success", $body);
                tilesview_log("=== API CALL END (SUCCESS) ===");
                return $body;
            }
        } else {
            tilesview_log("API Error: HTTP " . $http_code, [
                'response_body' => $body,
                'raw_body' => $raw_body
            ]);
            tilesview_log("=== API CALL END (ERROR) ===");
            return false;
        }
    }

    /** --------------
     * PRODUCT: CREATE / UPDATE (unchanged from previous fix)
     */
    public function on_product_create($product_id) {
        tilesview_log("Product create triggered", ['product_id' => $product_id]);
        $this->handle_product_sync($product_id, false);
    }

    public function on_product_update($product_id) {
        tilesview_log("Product update triggered", ['product_id' => $product_id]);
        $this->handle_product_sync($product_id, true);
    }

    private function handle_product_sync($post_id, $is_update) {
        tilesview_log("Product sync triggered", [
            'post_id' => $post_id,
            'is_update' => $is_update
        ]);

        if (get_post_type($post_id) != "product") {
            tilesview_log("Skipping non-product post");
            return;
        }

        if (wp_is_post_revision($post_id)) {
            tilesview_log("Skipping revision");
            return;
        }

        $post = get_post($post_id);
        if ($post->post_status !== 'publish') {
            tilesview_log("Skipping non-published product");
            return;
        }

        $product = wc_get_product($post_id);
        if (!$product) {
            tilesview_log("ERROR: Could not load WooCommerce product");
            return;
        }

        tilesview_log("Processing product", [
            'id' => $post_id,
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'type' => $product->get_type()
        ]);

        // Build payload
        $payload = $this->build_product_payload($product);
        if (!$payload) {
            tilesview_log("ERROR: Could not build product payload");
            return;
        }

        // Check if this is an update or insert
        $tv_id = get_post_meta($post_id, '_tilesview_id', true);
        tilesview_log("Existing TilesView ID: " . ($tv_id ?: 'None'));

        if (!$tv_id) {
            // INSERT (POST)
            tilesview_log("Performing product INSERT");
            $response = $this->api_call('product', 'POST', $payload, 'Product Insert - ID: ' . $post_id);

            if ($response) {
                $new_tv_id = $this->extract_tv_id_from_response($response);
                if ($new_tv_id) {
                    update_post_meta($post_id, '_tilesview_id', $new_tv_id);
                    tilesview_log("Product inserted successfully", [
                        'wc_product_id' => $post_id,
                        'tv_prod_id' => $new_tv_id
                    ]);
                } else {
                    tilesview_log("ERROR: Could not extract tv_prod_id from response", $response);
                }
            } else {
                tilesview_log("ERROR: Product insert failed");
            }
        } else {
            // UPDATE (PUT)
            tilesview_log("Performing product UPDATE");
            $payload['tv_prod_id'] = $tv_id;
            $response = $this->api_call('product', 'PUT', $payload, 'Product Update - ID: ' . $post_id);

            if ($response) {
                tilesview_log("Product updated successfully", [
                    'wc_product_id' => $post_id,
                    'tv_prod_id' => $tv_id
                ]);
            } else {
                tilesview_log("ERROR: Product update failed");
            }
        }
    }

    /** --------------
     * PRODUCT: DELETE/TRASH/RESTORE - ENHANCED WITH DEBUGGING ✅
     */
    public function on_product_trash($post_id) {
        tilesview_log("=== PRODUCT TRASH TRIGGERED ===");
        tilesview_log("Post ID: " . $post_id);
        tilesview_log("Hook: wp_trash_post");
        
        if (get_post_type($post_id) !== "product") {
            tilesview_log("Skipping non-product post (post_type: " . get_post_type($post_id) . ")");
            return;
        }

        // Get TilesView ID before processing
        $tv_id = get_post_meta($post_id, '_tilesview_id', true);
        tilesview_log("TilesView ID found: " . ($tv_id ?: 'None'));
        
        if (!$tv_id) {
            tilesview_log("No TilesView ID found, skipping trash sync");
            return;
        }

        // Get product info for logging
        $post = get_post($post_id);
        tilesview_log("Product details", [
            'post_id' => $post_id,
            'post_title' => $post->post_title,
            'post_status' => $post->post_status,
            'tv_id' => $tv_id
        ]);

        // Delete from TilesView when trashed
        $this->delete_from_tilesview($post_id, $tv_id, 'Product Trash');
    }

    public function on_product_delete($post_id, $post = null) {
        tilesview_log("=== PRODUCT DELETE TRIGGERED ===");
        tilesview_log("Post ID: " . $post_id);
        tilesview_log("Hook: before_delete_post");
        
        // Enhanced debugging
        if ($post) {
            tilesview_log("Post object available", [
                'post_type' => $post->post_type,
                'post_title' => $post->post_title,
                'post_status' => $post->post_status
            ]);
        } else {
            tilesview_log("No post object passed, fetching manually");
            $post = get_post($post_id);
            if ($post) {
                tilesview_log("Post fetched manually", [
                    'post_type' => $post->post_type,
                    'post_title' => $post->post_title,
                    'post_status' => $post->post_status
                ]);
            } else {
                tilesview_log("ERROR: Could not fetch post object");
            }
        }

        if (!$post || $post->post_type !== "product") {
            tilesview_log("Skipping non-product post");
            return;
        }

        // Get TilesView ID
        $tv_id = get_post_meta($post_id, '_tilesview_id', true);
        tilesview_log("TilesView ID found: " . ($tv_id ?: 'None'));
        
        if (!$tv_id) {
            tilesview_log("No TilesView ID found, skipping delete sync");
            return;
        }

        // Delete from TilesView when permanently deleted
        $this->delete_from_tilesview($post_id, $tv_id, 'Product Permanent Delete');
    }

    public function on_product_restore($post_id) {
        tilesview_log("=== PRODUCT RESTORE TRIGGERED ===");
        tilesview_log("Post ID: " . $post_id);
        tilesview_log("Hook: untrash_post");
        
        if (get_post_type($post_id) !== "product") {
            tilesview_log("Skipping non-product post");
            return;
        }

        // Get TilesView ID
        $tv_id = get_post_meta($post_id, '_tilesview_id', true);
        if (!$tv_id) {
            tilesview_log("No TilesView ID found, product may need to be re-synced");
            return;
        }

        tilesview_log("Product restored from trash", [
            'post_id' => $post_id,
            'tv_id' => $tv_id
        ]);

        // Re-sync the product when restored (optional)
        $product = wc_get_product($post_id);
        if ($product) {
            $this->handle_product_sync($post_id, true);
        }
    }

    private function delete_from_tilesview($post_id, $tv_id, $context) {
        tilesview_log("Performing TilesView delete", [
            'wc_product_id' => $post_id,
            'tv_prod_id' => $tv_id,
            'context' => $context
        ]);

        $payload = [
            'tv_prod_ids' => [(int)$tv_id],
            'hard_delete' => true
        ];

        tilesview_log("Delete payload", $payload);

        $response = $this->api_call('product', 'DELETE', $payload, $context . ' - ID: ' . $post_id);

        if ($response) {
            delete_post_meta($post_id, '_tilesview_id');
            tilesview_log("Product deleted successfully from TilesView", [
                'wc_product_id' => $post_id,
                'tv_prod_id' => $tv_id
            ]);
        } else {
            tilesview_log("ERROR: TilesView delete failed", [
                'wc_product_id' => $post_id,
                'tv_prod_id' => $tv_id
            ]);
        }
    }

    private function build_product_payload($product) {
        $pid = $product->get_id();

        tilesview_log("Building product payload for ID: " . $pid);

        $categories = wp_get_post_terms($pid, 'product_cat', ['fields' => 'names']);
        $first_category = !empty($categories) && !is_wp_error($categories) ? $categories[0] : 'Uncategorized';

        // Use WooCommerce getter functions for uniformity
        $sku = $product->get_sku();
        $price = $product->get_price();
        $name = $product->get_name();
        $image_id = $product->get_image_id();
        $image_url = (!empty($image_id) && is_numeric($image_id)) ? wp_get_attachment_url($image_id) : '';
        $is_bookmatch = (int)(get_post_meta($pid, 'is_bookmatch', true) ?: 0);
        $product_surface = get_post_meta($pid, 'product_surface', true) ?: "floor,wall";

        // Fixed fields as in manual function
        $payload = [
            "category" => [$first_category], // single category
            "sku" => $sku,
            "name" => $name,
            "price" => $price ? (string)$price : '',
            "price_type" => 0, // fixed as per manual code
            "image_path" => $image_url,
            "thumb_path" => $image_url,
            "surface_type" => "0", // fixed default per manual code
            "product_surface" => $product_surface, // fixed
            "is_bookmatch" => $is_bookmatch,
        ];

        // Log and validate minimal detail
        if (!$sku) {
            tilesview_log("WARNING: Product SKU missing for ID $pid");
        }
        if (!$name) {
            tilesview_log("ERROR: Product name missing for ID $pid");
            return false;
        }

        return $payload;
    }
    
    private function get_product_image($product) {
        $img_id = $product->get_image_id();
        if (!$img_id) {
            tilesview_log("No image ID found for product");
            return '';
        }

        $src = wp_get_attachment_image_src($img_id, 'full');
        if (!$src || empty($src[0])) {
            tilesview_log("Could not get image URL for attachment ID: " . $img_id);
            return '';
        }

        tilesview_log("Product image URL: " . $src[0]);
        return $src[0];
    }

    private function extract_tv_id_from_response($response) {
        // Try different response structures
        if (!empty($response['tv_prod_id'])) {
            return $response['tv_prod_id'];
        }
        if (!empty($response['data']['tv_prod_id'])) {
            return $response['data']['tv_prod_id'];
        }
        if (!empty($response['data']['data']['tv_prod_id'])) {
            return $response['data']['data']['tv_prod_id'];
        }
        return null;
    }

    /** --------------
     * CATEGORY: ADD/UPDATE (unchanged)
     */
    public function on_category_create($term_id, $tt_id) {
        tilesview_log("Category create triggered", ['term_id' => $term_id]);

        $term = get_term($term_id, 'product_cat');
        if (!$term || is_wp_error($term)) {
            tilesview_log("ERROR: Could not load category term");
            return;
        }

        $height = (int)(get_term_meta($term_id, 'height', true) ?: 600);
        $width = (int)(get_term_meta($term_id, 'width', true) ?: 600);

        $payload = [
            'name'   => $term->name,
            'height' => $height,
            'width'  => $width,
        ];

        tilesview_log("Creating category", $payload);

        $response = $this->api_call('category/', 'POST', $payload, 'Category Create - ID: ' . $term_id);

        if ($response) {
            $tv_cat_id = $this->extract_tv_id_from_response($response);
            if ($tv_cat_id) {
                update_term_meta($term_id, '_tilesview_cat_id', $tv_cat_id);
                tilesview_log("Category created successfully", [
                    'wc_term_id' => $term_id,
                    'tv_cat_id' => $tv_cat_id
                ]);
            }
        } else {
            tilesview_log("ERROR: Category create failed");
        }
    }

    public function on_category_update($term_id, $tt_id) {
        tilesview_log("Category update triggered", ['term_id' => $term_id]);

        $term = get_term($term_id, 'product_cat');
        if (!$term || is_wp_error($term)) {
            tilesview_log("ERROR: Could not load category term");
            return;
        }

        $tv_cat_id = get_term_meta($term_id, '_tilesview_cat_id', true);
        if (!$tv_cat_id) {
            tilesview_log("No TilesView category ID found, performing create instead");
            $this->on_category_create($term_id, $tt_id);
            return;
        }

        $height = (int)(get_term_meta($term_id, 'height', true) ?: 600);
        $width = (int)(get_term_meta($term_id, 'width', true) ?: 600);

        $payload = [
            'tv_prod_id' => $tv_cat_id,
            'name'       => $term->name,
            'height'     => $height,
            'width'      => $width,
        ];

        tilesview_log("Updating category", $payload);

        $response = $this->api_call('category/', 'PUT', $payload, 'Category Update - ID: ' . $term_id);

        if ($response) {
            tilesview_log("Category updated successfully", [
                'wc_term_id' => $term_id,
                'tv_cat_id' => $tv_cat_id
            ]);
        } else {
            tilesview_log("ERROR: Category update failed");
        }
    }

    /** --------------
     * FILTER MANAGEMENT (unchanged)
     */
    public function add_filter($label, $values = []) {
        tilesview_log("Adding filter", ['label' => $label, 'values' => $values]);

        $payload = [
            'lbl_name' => $label,
            'values'   => $values,
        ];

        return $this->api_call('filters/', 'POST', $payload, 'Filter Add');
    }

    public function get_all_filters() {
        tilesview_log("Getting all filters");
        return $this->api_call('filters/', 'GET', null, 'Get All Filters');
    }

    public function get_filter_by_name($name) {
        tilesview_log("Getting filter by name", ['name' => $name]);

        $payload = ['lbl_name' => $name];
        return $this->api_call('filters', 'POST', $payload, 'Get Filter By Name');
    }

    /** --------------
     * BATCH OPERATIONS (unchanged)
     */
    public function batch_sync_products($products_data) {
        tilesview_log("Starting batch product sync", ['count' => count($products_data)]);

        if (count($products_data) > 100) {
            tilesview_log("ERROR: Batch size exceeds 100 products limit");
            return false;
        }

        $payload = ['product' => $products_data];
        return $this->api_call('product/sync/', 'POST', $payload, 'Batch Product Sync');
    }

    public function batch_sync_categories($categories_data) {
        tilesview_log("Starting batch category sync", ['count' => count($categories_data)]);

        if (count($categories_data) > 100) {
            tilesview_log("ERROR: Batch size exceeds 100 categories limit");
            return false;
        }

        $payload = ['category' => $categories_data];
        return $this->api_call('category/sync/', 'POST', $payload, 'Batch Category Sync');
    }
}
