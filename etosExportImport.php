<?php
/*
Plugin Name: WooCommerce CSV Updater
Description: Update WooCommerce products from a CSV file, with the option to stop the process, log changes, and track uploads.
Version: 0.2
Author: Gentian Lleshi
*/

// Add admin menu for CSV upload and export
add_action('admin_menu', 'wc_csv_updater_menu');

function wc_csv_updater_menu()
{
    add_menu_page('WooCommerce CSV Updater', 'CSV Updater', 'manage_options', 'wc-csv-updater', 'wc_csv_updater_page');
}

function wc_csv_updater_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    // Check processing status
    $is_processing = get_option('wc_csv_processing') === 'start';

    // Handle Stop Button
    if (isset($_POST['wc_csv_stop_process'])) {
        update_option('wc_csv_processing', 'stop');
        echo '<div class="notice notice-warning is-dismissible"><p>CSV processing has been stopped.</p></div>';
        $is_processing = false;
    }

    // Handle Start Button and File Upload
    if (isset($_POST['wc_csv_updater_submit']) && !empty($_FILES['csv_file']['tmp_name'])) {
        // Save the uploaded file to 'wp-content/uploads/' with a unique name (name + date)
        $upload_dir = wp_upload_dir();
        $filename = 'csv_upload_' . date('Y-m-d_H-i-s') . '.csv';
        $file_path = $upload_dir['path'] . '/' . $filename;

        if (move_uploaded_file($_FILES['csv_file']['tmp_name'], $file_path)) {
            update_option('wc_csv_processing', 'start'); // Reset the stop flag to allow processing
            wc_csv_updater_process_csv($file_path, $filename); // Process the CSV file
            echo '<div class="notice notice-success is-dismissible"><p>CSV Uploaded Successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Failed to upload CSV file.</p></div>';
        }
    }

    // Handle Export Products Button
    if (isset($_POST['wc_csv_export_products'])) {
        wc_csv_export_products(); // Call the export function
    }

    // Fetch and display past CSV uploads and logs
    $csv_logs = get_option('wc_csv_logs', []);
?>

    <style>
        /* Hide the actual file input */
        input[type="file"] {
            display: none;
        }

        /* Style the custom label */
        .custom-file-upload {
            display: inline-block;
            padding: 10px 20px;
            color: #000;
            background-color: transparent;
            border-radius: 3px;
            cursor: pointer;
            font-size: 16px;
            border: 1px solid gray;
        }

        /* Add hover effect */
        .custom-file-upload:hover {
            background-color: #005a8c;
        }

        /* Disable button style */
        .disabled-button {
            background-color: #ccc;
            color: #777;
            cursor: not-allowed;
        }

        /* Hide the stop processing button if not processing */
        .hide-stop-button {
            display: none;
        }

        /* Adjust the form spacing */
        form {
            margin-bottom: 20px;
        }
    </style>

    <div class="wrap">
        <h1>WooCommerce CSV Updater</h1>

        <div style="display: flex;margin-top: 20px; gap:2rem; width:100%">
            <form method="post" enctype="multipart/form-data" style="display: flex; gap:2rem">
                <label id="csv-label" class="custom-file-upload">
                    Choose CSV File
                    <input id="csv-file" type="file" name="csv_file" accept=".csv" required>
                </label>
                <input type="submit" id="upload-btn" name="wc_csv_updater_submit" class="button button-primary <?php echo empty($_FILES['csv_file']) ? 'disabled-button' : ''; ?>" value="Update Products" <?php echo empty($_FILES['csv_file']) ? 'disabled' : ''; ?>>
            </form>

            <form method="post" class="<?php echo $is_processing ? '' : 'hide-stop-button'; ?>">
                <input type="submit" name="wc_csv_stop_process" class="button button-secondary" value="Stop Processing" style="background-color: red; color:white; height: 100%;">
            </form>

            <form method="post" style="margin-left: auto;">
                <input type="submit" name="wc_csv_export_products" class="button button-primary" value="Export Products Details for FINANCA 5" style="height: 100%;">
            </form>
        </div>

        <h2>Upload History</h2>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>CSV File</th>
                    <th>Log File</th>
                    <th>Uploaded On</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (!empty($csv_logs)) :
                    $csv_logs_reversed = array_reverse($csv_logs);
                    foreach ($csv_logs_reversed as $log) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url($log['csv_link']); ?>" target="_blank"><?php echo esc_html($log['csv_name']); ?></a></td>
                            <td><a href="<?php echo esc_url($log['log_link']); ?>" target="_blank"><?php echo esc_html($log['log_name']); ?></a></td>
                            <td><?php echo esc_html($log['time']); ?></td>
                        </tr>
                    <?php endforeach;
                else : ?>
                    <tr>
                        <td colspan="3">No uploads yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
        // Detect when a CSV is uploaded and change label and button state
        const csvInput = document.getElementById('csv-file');
        const csvLabel = document.getElementById('csv-label');
        const uploadBtn = document.getElementById('upload-btn');

        csvInput.addEventListener('change', function() {
            if (csvInput.files.length > 0) {
                csvLabel.innerHTML = "CSV Uploaded";
                uploadBtn.classList.remove('disabled-button');
                uploadBtn.removeAttribute('disabled');
            }
        });
    </script>
<?php
}

function wc_csv_export_products()
{
    // Check if WooCommerce is active
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        wp_die('WooCommerce must be active for this feature to work.');
    }

    // Clear any previous output
    ob_clean();

    // Set headers for download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=woocommerce_products_' . date('Y-m-d_H-i-s') . '.csv');

    // Create a file pointer connected to the output stream
    $output = fopen('php://output', 'w');

    // Add CSV header row
    fputcsv($output, ['SKU', 'Title', 'Stock', 'Units Sold Annually', 'Brand', 'Category']);

    // Get WooCommerce products
    $args = [
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => 'publish',
    ];

    $products = get_posts($args);

    foreach ($products as $product_post) {
        $product = wc_get_product($product_post->ID);

        // Get SKU, Title, Stock, Units Sold Annually
        $sku = $product->get_sku();
        $title = html_entity_decode($product->get_name()); // Decode HTML entities
        $stock = $product->get_stock_quantity();
        $units_sold_annually = get_post_meta($product->get_id(), 'units_sold_annually', true); // Example custom field for units sold annually

        // Get Brand (assumes 'product_brand' is a custom taxonomy)
        $brand = wp_get_post_terms($product->get_id(), 'product_brand');
        $brand_name = !empty($brand) ? html_entity_decode($brand[0]->name) : ''; // Decode HTML entities

        // Get Category
        $categories = wp_get_post_terms($product->get_id(), 'product_cat');
        $category_names = !empty($categories) ? implode(', ', wp_list_pluck($categories, 'name')) : '';

        // Add product data to CSV
        fputcsv($output, [$sku, $title, $stock, $units_sold_annually, $brand_name, $category_names]);
    }

    fclose($output);
    exit();
}


function wc_csv_updater_process_csv($csv_file, $csv_name)
{
    // Check if WooCommerce is active
    if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        wp_die('WooCommerce must be active for this plugin to work.');
        return;
    }

    // Log file setup with timestamp
    $upload_dir = wp_upload_dir();
    $log_filename = 'log_' . date('Y-m-d_H-i-s') . '.txt';
    $log_file = $upload_dir['path'] . '/' . $log_filename;
    $log_file_url = $upload_dir['url'] . '/' . $log_filename;
    $csv_file_url = $upload_dir['url'] . '/' . $csv_name;

    // Store log and CSV information
    $csv_logs = get_option('wc_csv_logs', []);
    $csv_logs[] = [
        'csv_name' => $csv_name,
        'csv_link' => $csv_file_url,
        'log_name' => $log_filename,
        'log_link' => $log_file_url,
        'time'     => date('Y-m-d H:i:s')
    ];
    update_option('wc_csv_logs', $csv_logs);

    // Open the CSV file for reading
    if (($handle = fopen($csv_file, 'r')) !== false) {
        // Write to the log that processing started
        file_put_contents($log_file, "Processing started on " . date('Y-m-d H:i:s') . PHP_EOL, FILE_APPEND | LOCK_EX);

        // Skip header line if your CSV has a header
        fgetcsv($handle);

        // Loop through each line of the CSV
        while (($data = fgetcsv($handle, 1000, ',')) !== false) {
            // Check if the stop flag is set
            if (get_option('wc_csv_processing') === 'stop') {
                fclose($handle);
                file_put_contents($log_file, "Processing was stopped on " . date('Y-m-d H:i:s') . PHP_EOL, FILE_APPEND | LOCK_EX);
                echo '<div class="notice notice-warning is-dismissible"><p>CSV processing was stopped.</p></div>';
                return;
            }

            // Extract data from the new CSV structure
            $sku = sanitize_text_field($data[0]);  // Column A (bc)
            $title = sanitize_text_field($data[3]);  // Column B (Titulli)
            $description = wp_kses_post($data[3]);  // Column D (Pershkrim)
            $stock = intval($data[4]);  // Column E (SASI GJENDJE TOTALE)
            $upload_to_web = intval($data[6]);  // Column G ("DO TE HIDHEN NE WEB")
            $brand_name = sanitize_text_field($data[2]);  // Column C (Klasifikatori) for brand

            // Prepare log message
            $log_message = '';

            // Check if the brand exists, if not create it
            $brand = term_exists($brand_name, 'product_brand'); // 'product_brand' is the custom taxonomy for WooCommerce brands
            if (!$brand) {
                $new_brand = wp_insert_term($brand_name, 'product_brand');
                if (!is_wp_error($new_brand)) {
                    $brand_id = $new_brand['term_id'];
                    $log_message .= "Created new brand {$brand_name} with ID {$brand_id}." . PHP_EOL;
                } else {
                    $log_message .= "Error creating brand {$brand_name}: " . $new_brand->get_error_message() . PHP_EOL;
                    file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
                    continue; // Skip to the next product if brand creation fails
                }
            } else {
                $brand_id = $brand['term_id'];
            }

            // Check if the product exists by SKU
            $product_id = wc_get_product_id_by_sku($sku);

            // If $upload_to_web is not 1 and the product exists, delete the product

            // 			if ($upload_to_web !== 1) {
            // 				if ($product_id) {
            // 					wp_delete_post($product_id, true);  // Delete the product permanently
            // 					$log_message .= "Deleted product SKU {$sku}, Title: {$title}, Stock: {$stock}, Brand: {$brand_name}." . PHP_EOL;
            // 				}
            // 				file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
            // 				continue; // Skip to the next product
            // 			}

            // Continue to create or update the product if $upload_to_web is 1
            if ($product_id) {
                // Update existing product
                $product = wc_get_product($product_id);
                $log_message .= "Updated product SKU {$sku}, Title: {$title}, Stock: {$stock}, Brand: {$brand_name}." . PHP_EOL;
            } else {
                // Create a new simple product
                $product = new WC_Product_Simple();
                $product->set_sku($sku);
                $log_message .= "Added new product SKU {$sku}, Title: {$title}, Stock: {$stock}, Brand: {$brand_name}." . PHP_EOL;
            }

            // Update or set product details and ensure brand exists
            $product->set_name($title); // Set product title
            $product->set_short_description($description);
            $product->set_description($description);
            $product->set_stock_quantity($stock);

            // Ensure the brand exists and create it if it doesn't
            $brand_slug = sanitize_title($brand_name); // Create a slug from the brand name

            // Check if the brand with the slug exists (case-insensitive)
            $brand = get_term_by('name', $brand_name, 'product_brand', 'ARRAY_A');

            // If brand doesn't exist, create it
            if (!$brand) {
                $new_brand = wp_insert_term($brand_name, 'product_brand', ['slug' => $brand_slug]);
                if (!is_wp_error($new_brand)) {
                    $brand_id = $new_brand['term_id'];
                    $log_message .= "Created new brand {$brand_name} with slug {$brand_slug} and ID {$brand_id}." . PHP_EOL;
                } else {
                    // Log the error and skip the product if brand creation failed
                    $log_message .= "Error creating brand {$brand_name}: " . $new_brand->get_error_message() . PHP_EOL;
                    file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
                    continue; // Skip this product if the brand creation failed
                }
            } else {
                // If the brand exists, get the term ID
                $brand_id = $brand['term_id'];
                $log_message .= "Brand {$brand_name} already exists with slug {$brand_slug} and ID {$brand_id}." . PHP_EOL;
            }

            // Assign the brand to the product
            wp_set_object_terms($product->get_id(), $brand_id, 'product_brand');

            // Track stock
            $product->set_manage_stock(true);

            $product->save();

            // Write log to file
            file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
        }
        fclose($handle);

        // Write to the log that processing completed
        file_put_contents($log_file, "Processing finished on " . date('Y-m-d H:i:s') . PHP_EOL, FILE_APPEND | LOCK_EX);

        // Display success message
        echo '<div class="notice notice-success is-dismissible"><p>Products updated successfully! Changes have been logged.</p></div>';
    } else {
        wp_die('Unable to open CSV file.');
    }
}
