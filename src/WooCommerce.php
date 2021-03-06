<?php

namespace Netzstrategen\ShopStandards;

/**
 * WooCommerce related functionality.
 */
class WooCommerce {

  /**
   * Default minimum discount percentage to display product sale label.
   *
   * @var int
   */
  const SALE_BUBBLE_MIN_AMOUNT = 10;

  /**
   * Adds woocommerce specific settings.
   *
   * @implements woocommerce_get_settings_shop_standards
   */
  public static function woocommerce_get_settings_shop_standards(array $settings): array {
    $settings[] = [
      'type' => 'title',
      'name' => __('Coupon settings', Plugin::L10N),
    ];
    $settings[] = [
      'type' => 'checkbox',
      'id' => '_' . Plugin::L10N . '_disable_coupon_checkout',
      'name' => __('Disable coupon input field on checkout page', Plugin::L10N),
    ];
    $settings[] = [
      'type' => 'sectionend',
      'id' => Plugin::L10N,
    ];
    $settings[] = [
      'type' => 'title',
      'name' => __('Automatic sale category assignment', Plugin::L10N),
    ];
    $settings[] = [
      'type' => 'checkbox',
      'id' => '_' . Plugin::L10N . '_enable_auto_sale_category_assignment',
      'name' => __('Enable', Plugin::L10N),
      'show_if_checked' => 'option',
    ];
    $settings[] = [
      'type' => 'multiselect',
      'id' => '_' . Plugin::L10N . '_eligible_delivery_times',
      'name' => __('Eligible delivery times', Plugin::L10N),
      'options' => static::getTaxonomyTermsAsSelectOptions('product_delivery_times'),
      'css' => 'height:auto',
      'custom_attributes' => [
        'size' => wp_count_terms('product_delivery_times', ['hide_empty'=> false, 'parent' => 0]),
      ],
    ];
    $settings[] = [
      'type' => 'select',
      'id' => '_' . Plugin::L10N . '_sale_category',
      'name' => __('Sale category to assign', Plugin::L10N),
      'options' => static::getTaxonomyTermsAsSelectOptions('product_cat'),
    ];
    $settings[] = [
      'type' => 'sectionend',
      'id' => Plugin::L10N,
    ];
    $settings[] = [
      'name' => __('Products settings', Plugin::L10N),
      'type' => 'title',
    ];
    $settings[] = [
      'id' => '_minimum_sale_percentage_to_display_label',
      'type' => 'text',
      'name' => __('Minimum discount percentage to display product sale label', Plugin::L10N),
      'default' => static::SALE_BUBBLE_MIN_AMOUNT,
    ];
    $settings[] = [
      'id' => Plugin::L10N,
      'type' => 'sectionend',
    ];
    return $settings;
  }

  /**
   * Adds strike price for variable products above regular product price labels.
   *
   * @see https://github.com/woocommerce/woocommerce/issues/16169
   *
   * @implements woocommerce_variable_sale_price_html
   * @implements woocommerce_variable_price_html
   */
  public static function woocommerce_variable_sale_price_html($price, $product) {
    $sale_prices = [
      'min' => $product->get_variation_price('min', TRUE),
      'max' => $product->get_variation_price('max', TRUE),
    ];
    $regular_prices = [
      'min' => $product->get_variation_regular_price('min', TRUE),
      'max' => $product->get_variation_regular_price('max', TRUE),
    ];
    $regular_price = [wc_price($regular_prices['min'])];
    if ($regular_prices['min'] !== $regular_prices['max']) {
      $regular_price[] = wc_price($regular_prices['max']);
    }
    $sale_price = [wc_price($sale_prices['min'])];
    if ($sale_prices['min'] !== $sale_prices['max']) {
      $sale_price[] = wc_price($sale_prices['max']);
    }
    if (($sale_prices['min'] !== $regular_prices['min'] || $sale_prices['max'] !== $regular_prices['max'])) {
      if ($sale_prices['min'] !== $sale_prices['max'] || $regular_prices['min'] !== $regular_prices['max']) {
        $price = '<del>' . implode('-', $regular_price) . '</del> <ins>' . implode('-', $sale_price) . '</ins>';
      }
    }
    return $price;
  }

  /**
   * Enables revisions for product descriptions.
   *
   * @implements woocommerce_register_post_type_product
   */
  public static function woocommerce_register_post_type_product($args) {
    $args['supports'][] = 'revisions';
    return $args;
  }

  /**
   * Changes the minimum amount of variations to trigger the AJAX handling.
   *
   * In the frontend, if a variable product has more than 20 variations, the
   * data will be loaded by ajax rather than handled inline.
   *
   * @see https://woocommerce.wordpress.com/2015/07/13/improving-the-variations-interface-in-2-4/
   *
   * @implements woocommerce_ajax_variation_threshold
   */
  public static function woocommerce_ajax_variation_threshold($qty, $product) {
    return 100;
  }

  /**
   * Adds backordering with proper status messages.
   *
   * Backordering is added and status messages displayed for every product
   * whether stock managing is enabled or it's available.
   *
   * @implements woocommerce_get_availability
   */
  public static function woocommerce_get_availability($stock, $product) {
    $product->set_manage_stock('yes');
    $product->set_backorders('yes');

    // If low stock threshold is not set at product level, get global option.
    // Zero is allowed so just check for empty string as in WooCommerce Core.
    // @see https://git.io/Je7ai
    if ($product->is_type('variation')) {
      $parent_product = wc_get_product($product->get_parent_id());
      $low_stock_amount = $parent_product->get_low_stock_amount();
    }
    else {
      $low_stock_amount = $product->get_low_stock_amount();
    }
    if ($low_stock_amount === '') {
      $low_stock_amount = get_option('woocommerce_notify_low_stock_amount');
    }

    // Check global "Stock display format" option is set to show low stock notices.
    $show_low_stock_amount = get_option('woocommerce_stock_format') === 'low_amount';

    // is_in_stock() returns true if stock level is zero but backorders allowed.
    // If stock level below threshold (but above zero), show "Only x in stock".
    // Otherwise, show "In stock" (not shown by WooCommerce by default).
    $product_stock_quantity = $product->get_stock_quantity();
    if ($product->is_in_stock()) {
      if ($show_low_stock_amount && $product_stock_quantity > 0 && $product_stock_quantity <= $low_stock_amount) {
        $stock['availability'] = sprintf(__('Only %s in stock', Plugin::L10N), $product_stock_quantity);
        $stock['class'] = 'low-stock';
      }
      else {
        $stock['availability'] = __('In stock', 'woocommerce');
        $stock['class'] = 'in-stock';
      }
    }

    return $stock;
  }

  /**
   * Changes number of displayed products.
   *
   * @implement loop_shop_per_page
   */
  public static function loop_shop_per_page($cols) {
    return 24;
  }

  /**
   * Hides Add to Cart button for products that must not be sold online.
   */
  public static function is_purchasable($purchasable, $product) {
    $product_id = $product->get_id();
    $hide_add_to_cart = get_post_meta($product_id, '_' . Plugin::PREFIX . '_hide_add_to_cart_button', TRUE);
    return !wc_string_to_bool($hide_add_to_cart);
  }

  /**
   * Displays custom fields for single products.
   *
   * @implements woocommerce_product_options_general_product_data
   */
  public static function woocommerce_product_options_general_product_data() {
    // GTIN field.
    echo '<div class="options_group show_if_simple show_if_external">';
    woocommerce_wp_text_input([
      'id' => '_' . Plugin::PREFIX . '_gtin',
      'label' => __('GTIN', Plugin::L10N),
      'desc_tip' => 'true',
      'description' => __('Enter the Global Trade Item Number', Plugin::L10N),
    ]);
    echo '</div>';
    // ERP/Inventory ID field.
    echo '<div class="options_group show_if_simple show_if_external">';
    woocommerce_wp_text_input([
      'id' => '_' . Plugin::PREFIX . '_erp_inventory_id',
      'label' => __('ERP/Inventory ID', Plugin::L10N),
    ]);
    echo '</div>';
    // Show `sale price only` checkbox.
    echo '<div class="options_group">';
    woocommerce_wp_checkbox([
      'id' => '_' . Plugin::PREFIX . '_show_sale_price_only',
      'label' => __('Display sale price as normal price', Plugin::L10N),
    ]);
    echo '</div>';
    // Custom price label
    echo '<div class="options_group">';
    woocommerce_wp_text_input([
      'id' => '_' . Plugin::PREFIX . '_price_label',
      'label' => __('Custom price label', Plugin::L10N),
      'desc_tip' => 'true',
      'description' => __('The label will only be displayed if "Sale price was displayed as regular price" setting is checked.', Plugin::L10N),
    ]);
    echo '</div>';
    // Hide sale percentage flash label.
    echo '<div class="options_group">';
    woocommerce_wp_checkbox([
      'id' => '_' . Plugin::PREFIX . '_hide_sale_percentage_flash_label',
      'label' => __('Hide sale percentage bubble', Plugin::L10N),
    ]);
    echo '</div>';
    // Hide add to cart button.
    echo '<div class="options_group show_if_simple show_if_external">';
    woocommerce_wp_checkbox([
      'id' => '_' . Plugin::PREFIX . '_hide_add_to_cart_button',
      'label' => __('Hide add to cart button', Plugin::L10N),
    ]);
    echo '</div>';
    // Price comparison focus product.
    echo '<div class="options_group">';
    woocommerce_wp_checkbox([
      'id' => '_' . Plugin::PREFIX . '_price_comparison_focus',
      'label' => __('Price comparison focus product', Plugin::L10N),
    ]);
    echo '</div>';
  }

  /**
   * Adds product notes custom field.
   *
   * This field will be added as the last field in the product
   * general options section.
   *
   * @implements woocommerce_product_options_general_product_data
   */
  public static function productNotesCustomField() {
    echo '<div class="options_group show_if_simple show_if_variable show_if_external">';
    woocommerce_wp_textarea_input([
      'id' => '_' . Plugin::PREFIX . '_product_notes',
      'label' => __('Internal product notes', Plugin::L10N),
      'style' => 'min-height: 120px;',
    ]);
    echo '</div>';
  }

  /**
   * Saves custom fields for simple products.
   *
   * @implements woocommerce_process_product_meta
   */
  public static function woocommerce_process_product_meta($post_id) {
    $custom_fields = [
      '_' . Plugin::PREFIX . '_gtin',
      '_' . Plugin::PREFIX . '_erp_inventory_id',
      '_' . Plugin::PREFIX . '_product_notes',
      '_' . Plugin::PREFIX . '_price_label',
    ];

    foreach ($custom_fields as $field) {
      if (isset($_POST[$field])) {
        if (!is_array($_POST[$field]) && $_POST[$field]) {
          update_post_meta($post_id, $field, $_POST[$field]);
        }
        else {
          delete_post_meta($post_id, $field);
        }
      }
    }

    $custom_fields_checkbox = [
      '_' . Plugin::PREFIX . '_show_sale_price_only',
      '_' . Plugin::PREFIX . '_hide_add_to_cart_button',
      '_' . Plugin::PREFIX . '_price_comparison_focus',
      '_' . Plugin::PREFIX . '_hide_sale_percentage_flash_label',
    ];

    foreach ($custom_fields_checkbox as $field) {
      $value = isset($_POST[$field]) && !is_array($_POST[$field]) && wc_string_to_bool($_POST[$field]) ? 'yes' : 'no';
      update_post_meta($post_id, $field, $value);
    }
  }

  /**
   * Ensures new product are saved before updating its meta data.
   *
   * New products are still not saved when updated_post_meta hook is called.
   * Since we can not check if the meta keys were changed before running
   * our custom functions (see updateDeliveryTime and updateSalePercentage),
   * we are forcing the post to be saved before updating the meta keys.
   *
   * @implements woocommerce_process_product_meta
   */
  public static function saveNewProductBeforeMetaUpdate($post_id) {
    $product = wc_get_product($post_id);
    $product->save();
  }

  /*
   * Adds CSS override to wp_head.
   *
   * @implements wp_head
   */
  public static function wp_head() {
    global $post;
    // Hide Add to Cart button for variable products of specific brands.
    // The button is always visible also if no variant is selected.
    // If the product has a specific brand, the style gets overridden to hide the button again.
    if ($post && static::productHasSpecificTaxonomyTerm($post->ID, 'product_brand')) {
      echo '<style>.single_variation_wrap .variations_button { display: none; }</style>';
    }
  }

  /**
   * Hides 'add to cart' button for products from specific categories or brands.
   *
   * @implements wp
   */
  public static function wp() {
    if (empty($product = wc_get_product())) {
      return;
    }
    $product_id = $product->get_id();
    if (static::productHasSpecificTaxonomyTerm($product_id, 'product_cat') || static::productHasSpecificTaxonomyTerm($product_id, 'product_brand')) {
      // If the product is a variation, to preserve the variation dropdown
      // select, we need to remove the single variation add to cart button.
      if ($product->is_type('variable')) {
        remove_action('woocommerce_single_variation', 'woocommerce_single_variation_add_to_cart_button', 20);
      }
      else {
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
      }
    }
  }

  /**
   * Displays order notice for products that must not be sold online.
   */
  public static function woocommerce_single_product_summary() {
    if (function_exists('get_field') && empty($notice = get_field('acf_hide_add_to_cart_product_notice', 'option')) || empty($product = wc_get_product())) {
      return;
    }
    $product_id = $product->get_id();

    if (static::productHasSpecificTaxonomyTerm($product_id, 'product_cat') || static::productHasSpecificTaxonomyTerm($product_id, 'product_brand')) {
      echo '<div class="info-notice">' . $notice . '</div>';
    }
  }

  /**
   * Checks whether a given post has a given term.
   */
  public static function productHasSpecificTaxonomyTerm($post_id, $taxonomy_name) {
    if (function_exists('get_field') && $excluded_terms = get_field('acf_hide_add_to_cart_' . $taxonomy_name, 'option')) {
      return has_term($excluded_terms, $taxonomy_name, $post_id);
    }
  }

  /**
   * Creates custom fields for product variations.
   *
   * @implements woocommerce_product_after_variable_attributes
   */
  public static function woocommerce_product_after_variable_attributes($loop, $variation_id, $variation) {
    // Variation GTIN field.
    echo '<div style="clear:both">';
    woocommerce_wp_text_input([
      'id' => '_' . Plugin::PREFIX . '_gtin[' . $loop . ']',
      'label' => __('GTIN:', Plugin::L10N),
      'placeholder' => __('Enter the Global Trade Item Number', Plugin::L10N),
      'value' => get_post_meta($variation->ID, '_' . Plugin::PREFIX . '_gtin', TRUE),
    ]);
    echo '</div>';
    // Variation ERP/Inventory ID field.
    echo '<div style="clear:both">';
    woocommerce_wp_text_input([
      'id' => '_' . Plugin::PREFIX . '_erp_inventory_id[' . $loop . ']',
      'label' => __('ERP/Inventory ID:', Plugin::L10N),
      'value' => get_post_meta($variation->ID, '_' . Plugin::PREFIX . '_erp_inventory_id', TRUE),
    ]);
    echo '</div>';
    // Variation hide add to cart button.
    echo '<div style="clear:both">';
    woocommerce_wp_checkbox([
      'id' => '_' . Plugin::PREFIX . '_hide_add_to_cart_button_' . $variation->ID,
      'label' => __('Hide add to cart button', Plugin::L10N),
      'value' => get_post_meta($variation->ID, '_' . Plugin::PREFIX . '_hide_add_to_cart_button', TRUE),
    ]);
    echo '</div>';
    // Insufficient variant images button checkbox.
    echo '<div style="clear:both">';
    woocommerce_wp_checkbox([
      'id' => '_' . Plugin::PREFIX . '_insufficient_variant_images_' . $variation->ID,
      'label' => __('Variation has insufficient images', Plugin::L10N),
      'value' => get_post_meta($variation->ID, '_' . Plugin::PREFIX . '_insufficient_variant_images', TRUE),
      'description' => __('Allows this product to be identified and possibly be excluded by other processes and plugins (e.g. a custom filter for product feeds). Enabling this option has no effect on the output (by default).', Plugin::L10N),
      'desc_tip' => TRUE,
    ]);
    echo '</div>';
    // Price comparison focus product.
    echo '<div style="clear:both">';
    woocommerce_wp_checkbox([
      'id' => '_' . Plugin::PREFIX . '_price_comparison_focus',
      'label' => __('Price comparison focus product', Plugin::L10N),
      'value' => get_post_meta($variation->ID, '_' . Plugin::PREFIX . '_price_comparison_focus', TRUE),
    ]);
    echo '</div>';
  }

  /**
   * Saves custom fields for product variations.
   *
   * @implements woocommerce_save_product_variation
   */
   public static function woocommerce_save_product_variation($post_id, $loop) {
    if (!isset($_POST['variable_post_id'])) {
      return;
    }
    $variation_id = $_POST['variable_post_id'][$loop];

    // Variation GTIN and ERP/Inventory ID fields.
    $custom_fields = [
      '_' . Plugin::PREFIX . '_gtin',
      '_' . Plugin::PREFIX . '_erp_inventory_id',
    ];
    foreach ($custom_fields as $field) {
      if (isset($_POST[$field]) && isset($_POST[$field][$loop])) {
        if ($_POST[$field][$loop]) {
          update_post_meta($variation_id, $field, $_POST[$field][$loop]);
        }
        else {
          delete_post_meta($variation_id, $field);
        }
      }
    }

    // Hide add to cart button.
    $hide_add_to_cart_button = isset($_POST['_' . Plugin::PREFIX . '_hide_add_to_cart_button_' . $variation_id]) && wc_string_to_bool($_POST['_' . Plugin::PREFIX . '_hide_add_to_cart_button_' . $variation_id]) ? 'yes' : 'no';
    update_post_meta($variation_id, '_' . Plugin::PREFIX . '_hide_add_to_cart_button', $hide_add_to_cart_button);

    // Insufficient images checkbox.
    $insufficient_variant_images = isset($_POST['_' . Plugin::PREFIX . '_insufficient_variant_images_' . $variation_id]) && wc_string_to_bool($_POST['_' . Plugin::PREFIX . '_insufficient_variant_images_' . $variation_id]) ? 'yes' : 'no';
    update_post_meta($variation_id, '_' . Plugin::PREFIX . '_insufficient_variant_images', $insufficient_variant_images);

    // Price comparison focus product.
    $price_comparison_focus = isset($_POST['_' . Plugin::PREFIX . '_price_comparison_focus']) && wc_string_to_bool($_POST['_' . Plugin::PREFIX . '_price_comparison_focus']) ? 'yes' : 'no';
    update_post_meta($variation_id, '_' . Plugin::PREFIX . '_price_comparison_focus', $price_comparison_focus);
  }

  /**
   * Sorts products by _sale_percentage.
   *
   * @implements woocommerce_get_catalog_ordering_args
   */
  public static function woocommerce_get_catalog_ordering_args($args) {
    $orderby_value = isset($_GET['orderby']) ? wc_clean($_GET['orderby']) : apply_filters('woocommerce_default_catalog_orderby', get_option( 'woocommerce_default_catalog_orderby'));
    if ('sale_percentage' === $orderby_value) {
      $args['orderby'] = 'meta_value_num';
      $args['order'] = 'DESC';
      $args['meta_key'] = '_sale_percentage';
    }
    return $args;
  }

  /**
   * Adds custom sortby option.
   *
   * @implements woocommerce_catalog_orderby
   * @implements woocommerce_default_catalog_orderby_options
   */
  public static function orderbySalePercentage($sortby) {
    $sortby['sale_percentage'] = __('Sort by discount', 'shop-standards');
    return $sortby;
  }

  /**
   * Changes sale flash label to display sale percentage.
   *
   * @implements woocommerce_sale_flash
   */
  public static function woocommerce_sale_flash($output, $post, $product) {
    if ($product->get_type() === 'variation') {
      $sale_percentage = get_post_meta($product->get_parent_id(), '_sale_percentage', TRUE);
    }
    else {
      $sale_percentage = get_post_meta($product->get_id(), '_sale_percentage', TRUE);
    }
    $minimum_sale_percentage = get_option('_minimum_sale_percentage_to_display_label', static::SALE_BUBBLE_MIN_AMOUNT);
    if (((!is_single() && $sale_percentage >= $minimum_sale_percentage) || is_single()) && get_post_meta($product->get_id(), '_' . Plugin::PREFIX . '_hide_sale_percentage_flash_label', TRUE) !== 'yes') {
      $output = '<span class="onsale" data-sale-percentage="' . abs($sale_percentage) . '">-' . abs($sale_percentage) . '%</span>';
    }
    else {
      $output = '';
    }
    return $output;
  }

  /**
   * Displays sale price as regular price if custom field is checked.
   *
   * @implements woocommerce_get_price_html
   */
  public static function woocommerce_get_price_html($price, $product) {
    $product_id = $product->get_type() === 'variation' ? $product->get_parent_id() : $product->get_id();
    if (get_post_meta($product_id, '_' . Plugin::PREFIX . '_show_sale_price_only', TRUE) === 'yes') {
      if ($product->get_type() === 'variable') {
        if ($product->get_variation_sale_price() === $product->get_variation_regular_price()) {
          return $price;
        }
        $sale_prices = [
          'min' => $product->get_variation_price('min', TRUE),
          'max' => $product->get_variation_price('max', TRUE),
        ];
        $sale_price = [wc_price($sale_prices['min'])];
        if ($sale_prices['min'] !== $sale_prices['max']) {
          $sale_price[] = wc_price($sale_prices['max']);
        }
        $price = implode('-', $sale_price);
      }
      else {
        if (!$product->get_sale_price()) {
          return $price;
        }
        $price = wc_price(wc_get_price_to_display($product)) . $product->get_price_suffix();
      }
      // Invoke regular price_html filters (excluding this one).
      $current_filter = current_filter();
      $priority = has_filter($current_filter, __METHOD__);
      remove_filter($current_filter, __METHOD__);
      $price = apply_filters($current_filter, $price, $product);
      add_filter($current_filter, __METHOD__, $priority, 2);

      if (is_product() && !static::isSideProduct()) {
        $price_label = get_post_meta($product_id, '_' . Plugin::PREFIX . '_price_label', TRUE) ?: __('(Our price)', Plugin::L10N);
        $price .= ' ' . $price_label;
      }
    }
    return $price;
  }

  /**
   * Checks if displayed product is a related, cross-sell or up-sell product.
   *
   * @return bool
   *   TRUE is current displayed product is related, cross-sell or up-sell.
   */
  public static function isSideProduct() {
    global $woocommerce_loop;

    // For the main product in the product single view, $woocommerce_loop['name']
    // value is 'up-sells'. We can only detect it is the main product checking
    // that $woocommerce_loop['loop'] value is 1.
    if (isset($woocommerce_loop['loop']) && $woocommerce_loop['loop'] === 1) {
      return FALSE;
    }

    // At this point we have discarded the main product. Only side products like
    // related, cross-sells and up-sells should remain.
    if (isset($woocommerce_loop['name']) && in_array($woocommerce_loop['name'], ['related', 'cross-sells', 'up-sells'])) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Adds basic information (e.g. weight, sku, etc.) and product attributes to cart item data.
   *
   * @implements woocommerce_get_item_data
   */
  public static function woocommerce_get_item_data($data, $cartItem) {
    $product = ($cartItem['variation_id']) ? wc_get_product($cartItem['variation_id']) : wc_get_product($cartItem['product_id']);
    // Discard the product variation attributes already included in the parent product data to avoid duplicates.
    $stringified_data = serialize($data);
    $filtered_attributes = array_filter(static::getProductAttributes($product), function ($item) use ($stringified_data) {
      return strpos($stringified_data, '"' . $item['name'] . '"') === FALSE;
    });

    $separator = [[
      'key' => 'separator',
      'value' => '',
    ]];

    // Display delivery time from woocommerce-german-market first for each order item.
    // Adds a separator element before the attributes list of each order item.
    if (count($data) > 1) {
      $found = FALSE;
      for ($pos = 0; $pos < count($data); $pos++) {
        if (in_array(__('Delivery Time', 'woocommerce-german-market'), $data[$pos], TRUE)) {
          $found = TRUE;
          break;
        }
      }
      if ($found) {
        $data = array_merge(array_splice($data, $pos, 1), $separator, $data);
      }
    }
    else {
      $data = array_merge($data, $separator);
    }

    // Add product data (SKU, dimensions and weight) and attributes.
    // Note: we display parent attributes for production variations.
    $product_data_set = array_merge(static::getProductData($product), $data, $filtered_attributes);

    return $product_data_set;
  }

  /**
   * Adds basic information (e.g. weight, sku, etc.) and product attributes to order emails.
   *
   * @param string $html
   *  Rendered list of product meta.
   *
   * @param object $item
   *  The processed item object.
   *
   * @param array $args
   *   The array of formatting arguments.
   *
   * @implements woocommerce_display_item_meta
   */
  public static function woocommerce_display_item_meta($html, $item, $args) {
    $strings = [];
    $product = $item->get_product();
    $data = static::getProductData($product);
    // @todo The separator element is stripped from the sent mail.
    $data[] = [
      'name' => '',
      'value' => '<hr>',
    ];
    // Add product meta which is contained in $html after product data.
    foreach ($item->get_formatted_meta_data() as $meta_id => $meta) {
      $data[] = [
        'name' => $meta->display_key,
        'value' => strip_tags($meta->display_value),
      ];
    }
    $product_data_set = array_merge($data, static::getProductAttributes($product));

    // Display delivery time from woocommerce-german-market for each order item.
    $delivery_time = wc_get_order_item_meta($item->get_id(), '_deliverytime');
    if ($delivery_time && $delivery_time = get_term($delivery_time, 'product_delivery_times')) {
      array_splice($product_data_set, 1, 0, [[
        'name' => __('Delivery Time', 'woocommerce-german-market'),
        'value' => $delivery_time->name,
      ]]);
    }

    foreach ($product_data_set as $productData) {
      $string = NULL;
      if (!empty($productData['name'])) {
        $string .= '<strong class="wc-item-meta-label">' . $productData['name'] . ':</strong> ';
      }
      if (isset($productData['value'])) {
        $string .= $productData['value'];
      }
      if (isset($string)) {
        $strings[] = $string;
      }
    }

    if ($strings) {
      $html = $args['before'] . implode($args['separator'], $strings) . $args['after'];
    }
    return $html;
  }

  /**
   * Removes SKU from order item name, added by woocommerce-german-market.
   *
   * @implements woocommerce_email_order_items_args
   */
  public static function woocommerce_email_order_items_args($args) {
    $args['show_sku'] = FALSE;
    return $args;
  }

  /**
   * Ensures product details column is wide enough.
   *
   * @implements woocommerce_email_styles
   */
  public static function woocommerce_email_styles($css) {
    $css .= '.order_item td:first-child {width: 75%;}';
    return $css;
  }

  /**
   * Retrieves basic data (SKU, dimensions and weight) for a given product.
   *
   * @param WC_Product $product
   *   Product for which data has to be retrieved.
   *
   * @return array
   *   Set of product data including weight, dimensions and SKU.
   */
  public static function getProductData(\WC_Product $product) {
    $product_data = [];
    $product_data_label = [];
    $product_data_value = [];

    // Adds sku to the cart item data.
    if ($sku = $product->get_sku()) {
      $product_data_label[] = __('SKU', 'woocommerce');
      $product_data_value[] = $sku;
    }
    if ($erp_id = get_post_meta($product->get_id(), '_' . Plugin::PREFIX . '_erp_inventory_id', TRUE)) {
      $product_data_label[] = __('ERP/ID', Plugin::L10N);
      $product_data_value[] = $erp_id;
    }
    if ($product_data_value) {
      $product_data[] = [
        'name' => implode(' | ', $product_data_label),
        'value' => implode(' | ', $product_data_value),
      ];
    }

    // Adds dimensions to the cart item data.
    if ($dimensions_value = array_filter($product->get_dimensions(FALSE))) {
      $product_data[] = [
        'name' => __('Dimensions', 'woocommerce'),
        'value' => wc_format_dimensions($dimensions_value),
      ];
    }

    // Adds weight to the cart item data.
    if ($weight_value = $product->get_weight()) {
      $product_data[] = [
        'name' => __('Weight', 'woocommerce'),
        'value' => $weight_value . ' kg',
      ];
    }

    return apply_filters(Plugin::PREFIX . '_product_data', $product_data);
  }

  /**
   * Retrieves the attributes of a given product.
   *
   * @param WC_Product $product
   *   Product for which attributes should be retrieved.
   *
   * @return array
   *   List of attributes of the product.
   */
  public static function getProductAttributes(\WC_Product $product) {
    $data = [];
    if ($parent_id = $product->get_parent_id()) {
      $product = wc_get_product($parent_id);
    }

    if ($product->get_type() === 'variable') {
      $variation_attributes = $product->get_variation_attributes();
    }
    else {
      $variation_attributes = [];
    }
    $attributes = $product->get_attributes();

    foreach ($attributes as $key => $attribute) {
      // Skips variation selected attributes to avoid displaying duplicates.
      if (isset($variation_attributes[$key])) {
        continue;
      }
      if ($attribute['is_taxonomy'] && $attribute['is_visible'] === 1) {
        $terms = wp_get_post_terms($product->get_id(), $attribute['name'], 'all');
        if (empty($terms)) {
          continue;
        }

        $taxonomy = $terms[0]->taxonomy;
        $taxonomy_object = get_taxonomy($taxonomy);
        $taxonomy_label = '';
        if (isset($taxonomy_object->labels->name)) {
          $taxonomy_label = str_replace(__('Product ', Plugin::L10N), '', $taxonomy_object->labels->name);
        }

        $data[] = [
          'name' => $taxonomy_label,
          'value' => implode(', ', wp_list_pluck($terms, 'name')),
        ];
      }
    }
    return $data;
  }

  /**
   * Adds missing postcode validation for some countries.
   *
   * @implements woocommerce_validate_postcode
   */
  public static function woocommerce_validate_postcode($valid, $postcode, $country) {
    switch ($country) {
      case 'DK':
      case 'BE':
      case 'LU':
      case 'CH':
        $valid = (bool) preg_match('@^\d{4}$@', $postcode);
        break;

      case 'LI':
        $valid = (bool) preg_match('@^(948[5-9])|(949[0-7])$@', $postcode);

      case 'NL':
        $valid = (bool) preg_match('@\d{4} ?[A-Z]{2}@', $postcode);
    }
    return $valid;
  }

  /**
   * Assigns sale category conditionally on product update.
   *
   * @implements woocommerce_update_product
   */
  public static function woocommerce_update_product($product_id) {
    $product = wc_get_product($product_id);
    $sale_category_id = (int) get_option('_' . Plugin::L10N . '_sale_category');

    if (!$product || !$sale_category_id) {
      return;
    }

    $current_category_ids = $product->get_category_ids();

    if ($product->is_type('variable')) {
      $variations = $product->get_available_variations();

      foreach ($variations as $variation) {
        $add_sale_category = static::checkAddToSaleCategory($variation['variation_id']);
        if ($add_sale_category) {
          break;
        }
      }
    }
    else {
      $add_sale_category = static::checkAddToSaleCategory($product_id, $product);
    }

    if ($add_sale_category) {
      $updated_category_ids = array_unique(array_merge($current_category_ids, [$sale_category_id]));
    }
    else {
      $updated_category_ids = array_diff($current_category_ids, [$sale_category_id]);
    }

    if ($updated_category_ids != $current_category_ids) {
      // Using the WordPress way of saving the terms for the product, because $product->set_category_ids() didn't work somehow.
      wp_set_object_terms($product_id, $updated_category_ids, 'product_cat');
    }
  }

  /**
   * Checks conditions required to add the sale category to a product.
   */
  public static function checkAddToSaleCategory($product_id, $product = FALSE) {
    if (!$product) {
      $product = wc_get_product($product_id);
    }

    $is_variation = $product->get_type() === 'variation';
    if ($is_variation) {
      $parent_id = $product->get_parent_id();
    }

    // Product needs to be in stock.
    $is_in_stock = $product->is_in_stock();

    // Delivery time needs to be in defined set of eligible options.
    $eligible_delivery_times = get_option('_' . Plugin::L10N . '_eligible_delivery_times');
    $delivery_time = get_post_meta($product_id, '_lieferzeit', TRUE);
    $has_eligible_delivery_time = in_array($delivery_time, $eligible_delivery_times);

    // Product needs to be on sale.
    $is_on_sale = $product->is_on_sale();

    // Sale label needs to be shown.
    $sale_label_is_visible = get_post_meta($is_variation ? $parent_id : $product_id, '_shop-standards_hide_sale_percentage_flash_label', TRUE) !== 'yes';

    // Discount needs to be at least the minimum sale percentage required to display the sale label.
    $sale_percentage = get_post_meta($is_variation ? $parent_id : $product_id, '_sale_percentage', TRUE);
    $minimum_sale_percentage = get_option('_minimum_sale_percentage_to_display_label', 10);
    $is_discounted_enough = $sale_percentage >= $minimum_sale_percentage;

    // Option "show sale price as regular price" needs to be deactivated.
    $option_is_deactivated = get_post_meta($is_variation ? $parent_id : $product_id, '_shop-standards_show_sale_price_only', TRUE) !== 'yes';

    // Check all conditions.
    $add_sale_category = $is_in_stock
                         && $has_eligible_delivery_time
                         && $is_on_sale
                         && $sale_label_is_visible
                         && $is_discounted_enough
                         && $option_is_deactivated;

    return $add_sale_category;
  }

  /**
   * Prepares taxonomy terms as select field options.
   */
  public static function getTaxonomyTermsAsSelectOptions($taxonomy) {
    $terms = get_terms([
      'taxonomy' => $taxonomy,
      'hide_empty' => false,
    ]);

    $term_options = array_reduce($terms, function($result, $term) {
      $result[$term->term_id] = $term->name;
      return $result;
    });

    return is_wp_error($terms) ? [] : $term_options;
  }

}
