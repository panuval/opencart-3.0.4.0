<?php 
namespace OCM\Lib;
use OCM as OcmCore; 
final class Xcombination {
    use OcmCore\Traits\Front\Crucify;
    use OcmCore\Traits\Front\Util;
    private $registry;
    private $types = array('product', 'manufacturer', 'category', 'option_value');
    private $mtype;
    private $offer_text = 'Offer Product';
    private $xcombinations = array();
    private $cart_combinations = array(); // existing cart combinations
    private $products_by_combination = array(); // added products by combination
    private $cart_products = array();
    private $route_combinations = array(); // combinations that passes all rules except product rules
    private $__require = array();
    private $__suggestion = array();
    private $__combination = false; // next potential combination
    private $__products = array(); // next potential combinational products
    private $__pending = array();
    private $__out_of_offers = array();
    private $ocm;
    public $warmed = false;
    public function __construct($registry) {
        $this->registry = $registry;
        $this->ocm = ($ocm = $this->registry->get('ocm_front')) ? $ocm : new OcmCore\Front($this->registry);
        $this->mtype = 'module';
        if ($this->ocm->getConfig('xcombination_status', $this->mtype)) {
            $this->xcombinations = $this->getCombinations();
        }
        $offer_text = $this->ocm->getConfig('xcombination_text', $this->mtype);
        $language_id = $this->config->get('config_language_id');
        if (isset($offer_text[$language_id])) {
            $this->offer_text = $this->ocm->html_decode($offer_text[$language_id]);
        }
        if (!isset($this->session->data['xc_total'])) {
            $this->session->data['xc_total'] = 0;
        }
    }
    public function __get($name) {
        return $this->registry->get($name);
    }
    // future expansion-  must use this function everywhere
    public function getCombinationById($combination_id) {
        return isset($this->xcombinations['xmethods'][$combination_id]) ? $this->xcombinations['xmethods'][$combination_id] : array();
    }
    public function getCombinations() {
        $xcombinations = $this->cache->get('ocm.xcombination');
        $disable_cache = $this->ocm->getConfig('xcombination_disable_cache', $this->mtype);
        if (!$xcombinations || $disable_cache) {
            $xmethods = array();
            $xmeta = array(
                'category'            => false,
                'manufacturer'        => false,
                'option_value'        => false
            );
            $xcombinations_rows = $this->db->query("SELECT * FROM `" . DB_PREFIX . "xcombination` order by `sort_order` asc")->rows;
            foreach($xcombinations_rows as $xcombinations_row) {
                $method_data = $xcombinations_row['method_data'];
                $combination_id = (int)$xcombinations_row['tab_id']; 
                $method_data = json_decode($method_data, true);
                /* cache only valid offers */
                if ($method_data && is_array($method_data) && $method_data['status']) {
                    $method_data =  $this->_resetEmptyRule($method_data);
                    $empty_option_rules = array();
                    foreach ($method_data['rules'] as $index => $rule) {
                        $items = array();
                        foreach ($this->types as $type) {
                            if (isset($rule[$type]) && is_array($rule[$type])) {
                                $items = $rule[$type];
                                break;
                            }
                        }
                        if ($items) {
                            if ($type == 'category' || $type == 'manufacturer' || $type == 'option_value') {
                                $xmeta[$type] = true;
                            }
                            if ($type == 'option_value' && !$rule['quantity']) {
                                $empty_option_rules[] = $rule[$type];
                                 unset($method_data['rules'][$index]); // remove this empty option rule and will merge with other rules
                            }
                        } else {
                            unset($method_data['rules'][$index]);
                        }
                    }
                    if (!$method_data['rules']) {
                        continue;
                    }
                    
                    // merge option rules if it is set to zero so the rule must be check rule + option
                    if ($empty_option_rules) {
                       foreach($empty_option_rules as $options) {
                            foreach($method_data['rules'] as $index => $rule) {
                                $method_data['rules'][$index]['options'] = $options;
                            }
                       }
                    }

                    $rules = $this->_findValidRules($method_data);
                    $rates = $this->_findRawRate($method_data);
                    $limit_ranges = $this->_findLimitRange($method_data);
                    $end = $method_data['date_end'] ? $method_data['date_end'] : 0;
                    $end = ($end && $method_data['time_end']) ? $end . sprintf(" %02d:", $method_data['time_end']).'00:00' : $end;
                    if ($end) {
                        $end = strtotime($end);
                    }
                    $ui = array(
                        'countdown' => !!$method_data['countdown'],
                        'rule'      => !!$method_data['rules_btn'],
                        'offer'     => !!$method_data['offer_btn'] && $method_data['product_source'] == 'custom'
                    );
                    $xmethods[$combination_id] = array(
                       'combination_id'  => $combination_id,
                       'rules'           => $rules,
                       'rates'           => $rates,
                       'ui'              => $ui,
                       'limit'           => (int)$method_data['limit'],
                       'limit_type'      => $method_data['limit_type'],
                       'limit_ranges'    => $limit_ranges,
                       'option_price'    => $method_data['option_price'],
                       'discount_option' => $method_data['discount_option'],
                       'suggestion'      => $method_data['suggestion'],
                       'offer'           => $method_data['offer'],
                       'skip'            => !!$method_data['skip'],
                       'mute'            => !!$method_data['mute'],
                       'singular'        => !!$method_data['singular'],
                       'auto'            => !!$method_data['auto'],
                       'page'            => $method_data['page'],
                       'type'            => $method_data['product_source'],
                       'end'             => $end
                    );
                }
            }
            $xcombinations = array('xmeta' => $xmeta, 'xmethods' => $xmethods);
            $this->cache->set('ocm.xcombination', $xcombinations);
        }
        return $xcombinations;
    }
    private function _findLimitRange($data) {
        $ranges = array();
        foreach($data['ranges'] as $range) {
            $start = (float)$range['start'];
            $end = (float)$range['end'];
            $limit = (int)$range['limit'];
            if ($start && !$end) {
                $end = PHP_INT_MAX;
            }
            $ranges[] = array('start' => $start, 'end' => $end, 'limit' => $limit);
        }
        return $ranges;
    }
    private function _findRawRate($data) {
        $rates = array();
        if ($data['product_source'] !== 'custom') {
            $discount = trim(trim($data['discount']), '-');
            if (substr($discount, -1) == '%') {
                $discount = rtrim($discount,'%');
                $rates['percent'] = true;
                $rates['value'] = (float)$discount / 100;
            } else {
                $rates['percent'] = false;
                $rates['value'] = (float)$discount;
            }
        } else {
           $products = array();
           foreach($data['products'] as $product_id => $discount) {
                $discount = trim(trim($discount), '-');
                if (substr($discount, -1) == '%') {
                    $percent = true;
                    $discount = rtrim($discount,'%');
                    $value = (float)$discount / 100;
                } else {
                    $percent = false;
                    $value = (float)$discount;
                }
                $products[$product_id] = array('percent' => $percent, 'value' => $value);
            }
            $rates['products'] = $products;
        }
        $rates['base_price'] = !!$data['base_price'];
        return $rates;
    }
    private function _findValidRules($data) {
        $rules = array();
        if ($data['store_all'] != 1) {
            $rules['store'] = array(
                'type' => 'in_array',
                'product_rule' => false,
                'value' => $data['store'],
                'compare_with' => 'store_id',
                'false_value' => false
            );
        }
        if ($data['customer_group_all'] != 1) {
            $rules['customer_group'] = array(
                'type' => 'in_array',
                'product_rule' => false,
                'value' => $data['customer_group'],
                'compare_with' => 'customer_group_id',
                'false_value' => false
            );
        }
        if ($data['days_all'] != 1 && is_array($data['days']) && $data['days'] && count($data['days']) !== 7) {
            $rules['days'] = array(
                'type' => 'in_array',
                'product_rule' => false,
                'value' => $data['days'],
                'compare_with' => 'day',
                'false_value' => false
            );
        }
        if ($data['date_start'] != "" && $data['date_end']) {
            $rules['date'] = array(
                'type' => 'in_between',
                'product_rule' => false,
                'start' => $data['date_start'],
                'end' => $data['date_end'],
                'compare_with' => 'date'
            );
        }
        if ($data['time_start'] != "" && $data['time_end'] != "") {
            $valid_hours = array();
            $time_start = (int)$data['time_start'];
            $time_end = (int)$data['time_end'];

            if ($time_start <= $time_end) {
               for ($i = $time_start; $i < $time_end ; $i++) { 
                  $valid_hours[] = $i;
               }
            } else {
               for ($i = 0; $i < $time_end ; $i++) { 
                  $valid_hours[] = $i;
               }
               for ($i = $time_start; $i <= 23 ; $i++) { 
                  $valid_hours[] = $i;
               }
            }
            if ($valid_hours) {
                $rules['time'] = array(
                    'type' => 'in_array',
                    'product_rule' => false,
                    'address_rule' => false,
                    'value' => $valid_hours,
                    'compare_with' => 'time',
                    'false_value' => false
                );
            }
        }
        /* Special rule if only ending time and date range set */
        if ($data['date_start'] != "" && $data['date_end'] && !$data['time_start'] && $data['time_end']) {
            $valid_hours = array();
            $time_start = 0;
            $time_end = (int)$data['time_end'];
            for ($i = $time_start; $i < $time_end ; $i++) { 
                  $valid_hours[] = $i;
            }
            $rules['date_time'] = array(
                'type' => 'in_array_not_equal',
                'product_rule' => false,
                'value' => $valid_hours,
                'compare_with' => 'time',
                'not_equal_value' => $data['date_end'],
                'not_equal_with' => 'date',
                'false_value' => false
            );
        }
        if ($data['rules']) {
            $rules['combination'] = array(
                'type' => 'function',
                'func' => '_validateCombination',
                'value' => $data['rules'],
                'compare_with' => 'cart',
                'rule_type'    => 'combination',
                'product_rule' => false,
                'false_value' => false
            );
        }
        return $rules;
    }
    private function _resetEmptyRule($data) {
        $rules = array(
            'store' => 'store_all',
            'customer_group' => 'customer_group_all',
            'days' => 'days_all'
        );
        foreach ($rules as $key => $value) {
            if (!isset($data[$value])) {
                $data[$value] = '';
            }
            if (!isset($data[$key]) || !$data[$key]) {
                $data[$value] = 1;
            }
        }
        /* setting default value if not set */
        if (!isset($data['limit'])) $data['limit'] = 1;
        if (!isset($data['rules_btn'])) $data['rules_btn'] = '';
        if (!isset($data['offer_btn'])) $data['offer_btn'] = '';
        if (!isset($data['countdown'])) $data['countdown'] = '';
        if (!isset($data['singular'])) $data['singular'] = '';
        if (!isset($data['base_price'])) $data['base_price'] = '';
        if (!isset($data['skip'])) $data['skip'] = '';
        if (!isset($data['mute'])) $data['mute'] = '';
        if (!isset($data['auto'])) $data['auto'] = '';
        if (!isset($data['limit_type'])) $data['limit_type'] = 'fixed';
        
        /* sanitize cost params  */
        if (empty($data['suggestion'])) $data['suggestion'] = array();
        if (empty($data['offer'])) $data['offer'] = array();
        if (empty($data['ranges'])) $data['ranges'] = array();
        if (empty($data['days'])) $data['days'] = array();
        if (empty($data['rules'])) $data['rules'] = array();
        if (empty($data['products'])) $data['products'] = array();
        if (empty($data['page'])) $data['page'] = array();
        if (empty($data['discount_option'])) $data['discount_option'] = array();
        return $data;
    }
    public function onCartUpdate($cart_id) {
        $cart = $this->ocm->getCartProductById($cart_id);
        $option = isset($cart['option']) ? $cart['option'] : array();
        $this->warmed = false; // set true and let re-fetch everything
        return isset($option['xcombination']) ? array('stop' => true) : false;
    }
    public function onCartProducts() {
        if (!$this->ocm->isAdmin() && !$this->warmed) {
            $this->warmUp();
        }
    }
    public function warmUp() {
        $this->init();
        $this->warmed = true;
        $is_complete = false;
        while(!$this->__combination) {
            $this->processPendingOffer();
            if (!$this->__combination) {
                $is_complete = $this->getCartCombinations();
            }
            if ($is_complete || $this->__combination) {
                break;
            }
        }
        // add total offer to header to sync up
        if ($this->response) {
            $total_offer = 0;
            foreach($this->cart_combinations as $each) {
                $total_offer += $each['quantity'];
            }
            $offer_flag = $total_offer != $this->session->data['xc_total'] ? 1 : 0;
            $this->session->data['xc_total'] = $total_offer;
            $this->response->addHeader('_xc_: ' .$offer_flag);
        }
    }
    public function applyCartPrice($data) {
        return $this->getCombinationPrice($data['cart'], $data['product'], $data['option_data'], $data['option_price']);
    }
    public function getCombinationPrice($cart, $product, $option_data = array(), $option_price = 0) {
        $return = array();
        $product_id = (int)$cart['product_id'];
        $option = $cart['option'];
        if ($this->ocm->isAdmin()) {
            foreach ($option as $key => $value) {
                if (!is_array($value)) {
                    $value = $this->ocm->html_decode($value);
                    if (strpos($value, '<xc>') !== false) {
                        $option['xcombination'] = $key;
                    }
                }
            }
        }
        if (!isset($option['xcombination']) || !$option['xcombination']) {
            return false;
        }
        $combination_id = $option['xcombination'];
        $rates = $this->getCombinationRateByProductId($combination_id, $product_id);
        if ($rates) {
            $xcombination = $this->getCombinationById($combination_id);
            // don't consider ocm_price as it applies disocunt to the latest price
            $original_price = $product['price'];
            if ($xcombination['rates']['base_price'] && !empty($product['ocm_price'])) {
                $original_price = $product['ocm_price'];
            }
            if (!$xcombination['rates']['base_price'] && (float)$product['special']) {
                $original_price = $product['special'];
            }
            $amount = $rates['percent'] ? ($rates['value'] * $original_price) : $rates['value'];
            $return['price'] = $original_price - $amount;
            if ($return['price'] < 0) {
                $return['price'] = 0;
            }
            // adjust rewward price if it has
            if (!empty($cart['reward'])) {
                $reward = $rates['percent'] ? ($rates['value'] * $cart['reward']) : $rates['value'];
                $return['reward'] = $cart['reward'] - $reward;
            }
            /* Calulate option price */
            $_paid_options_price = 0;
            $_discount_on_some_options = false;
            // $_option_key = $product_id;
            if (is_array($option_data) && $option_data) {
                foreach ($option_data as $each) {
                    if (!empty($each['option_value_id'])) {
                        if ($rates['option_price'] == 'some') {
                            if (!in_array($each['option_value_id'], $rates['discount_option'])) {
                                if ($each['price_prefix'] == '+') {
                                    $_paid_options_price += $each['price'];
                                } else {
                                    $_paid_options_price -= $each['price'];
                                }
                            } else {
                                $_discount_on_some_options = true;
                            }
                        }
                    }
                   // $_option_key .= '_' . $each['option_id'];
                }
            }
            $option_price -= $_paid_options_price;
            if ($option_price && ($rates['option_price'] == 'all' || $_discount_on_some_options)) {
                $_option_price = $this->applyOptionDiscount($option_price, $rates);
                if ($_option_price !== false) {
                    $return['option_price'] = $_option_price['price'] + $_paid_options_price;
                }
            }
            // end of option price
            
            // $cart_qnty = isset($cart['quantity']) ? $cart['quantity'] : $product['quantity'];
            $option_data[] = array(
                'product_option_id'       => $combination_id,
                'product_option_value_id' => $combination_id,
                'option_id'               => $combination_id,
                'option_value_id'         => $combination_id,
                'name'                    => '',
                'value'                   => '<xc>' . $this->offer_text . ' </xc>',
                'type'                    => 'text',
                'price'                   => 0,
                'price_prefix'            => '+'
            );
            $return['option_data'] = $option_data;
            // $return['name'] = '<xcp>'.$this->offer_text.'</xc> ' . $product['name'];
        }
        return $return ? $return : false;
    }
    public function getOptionPrice($price, $product_id, $prefix = '+', $option_value_id = 0) {
        if (!$this->__combination) {
            $this->init();
            $this->getCartCombinations();
        }
        if (!$this->__combination) {
            return false;
        }
        $rates = $this->getCombinationRateByProductId($this->__combination['combination_id'], $product_id);
        $option_discount_req = $rates && ($rates['option_price'] == 'all' || ($rates['option_price'] == 'some' && in_array($option_value_id, $rates['discount_option'])));
        if ($option_discount_req) {
            return $this->applyOptionDiscount($price, $rates);
        } else {
            return false;
        }
    }
    public function applyOptionDiscount($price, $rates) {
        if (!(float)$price || !$rates) {
            return false;
        }
        $return = array();
        $amount = $rates['percent'] ? ($rates['value'] * $price) : $rates['value'];
        $return['price'] = $price - $amount;
        if ($return['price'] < 0) {
            $return['price'] = 0;
        }
        return $return;
    }
    public function init() {
        $this->__pending    = array();
        $this->__suggestion = array();
        $this->analyzeCart();
        $this->clearCart();
    }
    public function getAvailCombination() {
        return $this->__combination;
    }
    public function getSuggestion() {
        if ($this->__suggestion) {
            $xcombination = $this->getCombinationById($this->__suggestion['combination_id']);
            if ($xcombination['type'] == 'custom') {
                if (!$this->checkStocks($xcombination['rates']['products'])) {
                    $this->__suggestion = array();
                }
            }
        }
        return $this->__suggestion;
    }
    public function getRouteCombinations() {
        $combinations = array();
        if ($this->route_combinations) {
            foreach ($this->route_combinations as $combination_id) {
                $combinations[] = $this->getCombinationById($combination_id);
            }
        }
        return $combinations ? array(
            'combinations' => $combinations,
            'xmeta'        => $this->xcombinations['xmeta']
        ) : false;
    }
    private function getOfferProducts($combination) {
        $products = array();
        $product_id = 0;
        if ($combination['type'] !== 'custom') {
            if ($this->__products) {
                if (count($this->__products) == 1) {
                    $product_id = $this->__products[0];
                    $products[$product_id] = $combination['rates'];
                } else {
                    foreach ($this->__products as $id) {
                       $products[$id] = $combination['rates'];
                    }
                    if ($combination['type'] == 'cheapest' || $combination['type'] == 'expensive') {
                        $product_id = $this->getCheapestOrExpensive($products, $combination['type']);
                        $products = array();
                        $products[$product_id] = $combination['rates'];
                    }
                }
            }
        } else {
            $products = $combination['rates']['products'];
            $products = $this->checkStocks($products);
             // remove already added products if singular mode is set
            if ($combination['singular'] && isset($this->products_by_combination[$combination['combination_id']])) {
                foreach ($this->products_by_combination[$combination['combination_id']] as $pid) {
                    if (isset($products[$pid])) {
                        unset($products[$pid]);
                    }
                }
            }

            if ($products) {
                $ids = array_keys($products);
                if (count($ids) == 1) {
                    $product_id = $ids[0];
                } else if ($combination['auto'] && $combination['singular']) {
                    $product_id = $ids[0]; // pick first if singular and auto mode is set
                }
            }
        }
        return array(
            'products'   => $products,
            'product_id' => $product_id
        );
    }
    private function checkStocks($products) {
        if ($this->config->get('config_stock_checkout')) return $products;
        $product_ids = array_keys($products);
        if ($product_ids) {
            $rows = $this->db->query("SELECT product_id, quantity FROM " . DB_PREFIX . "product WHERE product_id IN (" . implode(',', $product_ids) . ")")->rows;
            foreach ($rows as $row) {
                if (!$row['quantity']) {
                    unset($products[$row['product_id']]);
                }
            }
        }
        return $products;
    }
    private function applyCombinationToCart($combination, $cart_sync = true) {
        $this->load->model('catalog/product');
        $applied = false;
        $product_id = 0;
        $quantity = (int)$combination['avail'];
        $_products = $this->getOfferProducts($combination);
        // apply only if one product is available
        $product_id = $_products['product_id'];
        if ($product_id && $combination['auto']) {
            $product_options = $this->model_catalog_product->getProductOptions($product_id);
            if (!$product_options) {
                $option = array();
                $recurring_id = 0;
                $this->addCombinationToCart($product_id, $combination['combination_id'], $quantity, $option, $recurring_id, $cart_sync);
                $applied = true;
            }
        }
        $combination['products'] = $_products['products'];
        //auto apply failed, therefore, let customer choose the products
        if (!$applied && $_products['products'] && !$this->__combination) {
            $this->__combination = $combination;
        }
        // offer products are not available let mark this combination so no further process to this
        if (!$_products['products']) {
            $this->__out_of_offers[$combination['combination_id']] = true;
        }
        return $applied;
    }
    private function getCombinationRateByProductId($combination_id, $product_id) {
        $xcombination = $this->getCombinationById($combination_id);
        $rates = array();
        if (!$xcombination) {
            return $rates;
        }
        if ($xcombination['type'] !== 'custom') {
            $rates = $xcombination['rates'];
        } else {
            $_rates = $xcombination['rates'];
            foreach ($_rates['products'] as $_product_id => $value) {
                if ($_product_id == $product_id) {
                    $rates = $value;
                    break;
                }
            }
        }
        if ($rates) {
            $rates['option_price'] = $xcombination['option_price'];
            $rates['discount_option'] = $xcombination['discount_option'];
        }
        return $rates;
    }
    private function processPendingOffer() {
        if ($this->__pending) {
            foreach ($this->__pending as $combination_id => $pending) {
                $xcombination = $this->getCombinationById($combination_id);
                if ($xcombination) {
                    $this->__products       = $pending['products'];
                    $xcombination['avail']  = $pending['limit'];
                    $this->applyCombinationToCart($xcombination, false);
                    if ($this->__combination) {
                        break;
                    }
                }
            }
        }
    }
    public function addCombinationToCart($product_id, $combination_id, $quantity = 1, $option = array(), $recurring_id = 0, $cart_sync = true) {
        $option['xcombination'] = $combination_id;
        $this->cart->add($product_id, $quantity, $option, $recurring_id);
        if ($cart_sync) {
            $this->syncCartProducts($combination_id);
        }
        $this->__combination = false;
        $this->warmed = false;
    }
    public function getCartCombinations() {
        $xcombinations = $this->xcombinations['xmethods'];
        if (!$xcombinations) {
            return true;
        }
        if ($this->customer->isLogged()) {
            $customer_group_id = $this->customer->getGroupId();
        } else if(isset($this->session->data['customer']) && isset($this->session->data['customer']['customer_group_id']) && $this->session->data['customer']['customer_group_id']) {
            $customer_group_id = $this->session->data['customer']['customer_group_id'];
        } else {
            $customer_group_id = 0;
        }
        $compare_with = array();
        $compare_with['store_id'] = $this->config->get('config_store_id');
        $compare_with['customer_group_id'] = $customer_group_id;
        $compare_with['time'] = date('G');
        $compare_with['date'] = date('Y-m-d');
        $compare_with['day'] = date('w');
        $compare_with['cart'] = true;
        foreach($xcombinations as $xcombination) {
            $rules = $xcombination['rules'];
            $combination_id = $xcombination['combination_id'];
            if (isset($this->__out_of_offers[$combination_id])) {
                continue;
            }
            if (!empty($this->session->data['xc_skip']) && in_array($combination_id, $this->session->data['xc_skip'])) {
                continue;
            }
            $limit = $this->getOfferLimit($xcombination);
            $this->__products = array();
            $this->__require = array();
            $alive_or_dead = $this->_crucify($rules, $compare_with);
            if ($alive_or_dead['status']) {
                $xcombination['avail'] = $limit;
                $this->applyCombinationToCart($xcombination);
                $this->route_combinations[] = $combination_id;
                return false;
            } else if (count($alive_or_dead['debugging']) == 1 && isset($alive_or_dead['debugging']['combination'])) {
                $this->route_combinations[] = $combination_id;
                if ($xcombination['suggestion'] && !$this->__suggestion && $this->__require && !$this->isCartCombination($combination_id)) {
                    //make suggestion if available
                    $this->__suggestion = array(
                        'combination_id'  => $combination_id,
                        'mute'            => !!$xcombination['mute'],
                        'product_id'      => $this->__require['product_id'],
                        'option_value_id' => $this->__require['option_value_id'],
                        'limit'           => $limit,
                        'pages'           => $xcombination['suggestion']
                    );
                }
            }
        }
        return true;
    }
    public function getOfferLimit($xcombination, $no_of_set = 1) {
        if (isset($xcombination['limit_type']) && $xcombination['limit_type'] == 'range') {
            $limit = $this->_getLimitFromRange($xcombination['limit_ranges'], $no_of_set);
        } else {
            $limit = $xcombination['limit'] * $no_of_set;
        }
        return $no_of_set ? $limit : 0;
    }
    private function _getLimitFromRange($ranges, $target) {
      $limit = 1;
      foreach($ranges as $range) {
          if ($range['start'] <= $target && $target <= $range['end']) {
              $limit = $range['limit'];
              break;
          }
      }
      return $limit;
   }
    private function getProductOptionValues($option) {
        $types = array('select', 'radio', 'checkbox');
        $return = array();
        foreach ($option as $product_option_id => $product_option_value_ids) {
            $type = $this->db->query("SELECT o.type FROM " . DB_PREFIX . "product_option po LEFT JOIN `" . DB_PREFIX . "option` o ON (po.option_id = o.option_id) WHERE po.product_option_id = '" . (int)$product_option_id . "'")->row;
            if ($product_option_value_ids && $type && in_array($type['type'], $types)) {
                if (!is_array($product_option_value_ids)) {
                    $product_option_value_ids = array($product_option_value_ids);
                }
                $rows = $this->db->query("SELECT option_value_id FROM " . DB_PREFIX . "product_option_value WHERE product_option_value_id = '" . implode(',', $product_option_value_ids) . "'")->rows;
                foreach ($rows as $row) {
                    $return[] = $row['option_value_id'];
                }
            }
        }
        return $return;
    }
    private function analyzeCart() {
        $cart = $this->ocm->getCartProducts(true);
        $cart_products = array();
        $cart_combinations = array();
        $products_by_combination = array();
        foreach ($cart as &$product) {
            $option = $product['option'];
            if (!isset($option['xcombination'])) {
                $product['category'] = array();
                $product['option_value'] = array();
                $product['manufacturer'] = 0;
                $product['product'] = $product['product_id'];
                if ($this->xcombinations['xmeta']['manufacturer']) {
                    $manufacturer = $this->db->query("SELECT manufacturer_id FROM " . DB_PREFIX . "product WHERE product_id = '" . (int)$product['product_id'] . "'")->row;
                    if ($manufacturer && $manufacturer['manufacturer_id']) {
                        $product['manufacturer'] = $manufacturer['manufacturer_id'];
                    }
                }
                if ($this->xcombinations['xmeta']['category']) {
                    $categories = $this->db->query("SELECT category_id FROM " . DB_PREFIX . "product_to_category WHERE product_id = '" . (int)$product['product_id'] . "'")->rows;
                    if ($categories) {
                        foreach($categories as $category) {
                            $product['category'][] = $category['category_id'];
                        } 
                    }
                }
                if ($this->xcombinations['xmeta']['option_value'] && $option) {
                    $product['option_value'] = $this->getProductOptionValues($option);
                }
                $cart_products[$product['cart_id']] = $product;
            } else {
                $cart_combinations[$product['cart_id']] = array(
                    'cart_id'          => $product['cart_id'],
                    'quantity'         => $product['quantity'],
                    'product_id'       => $product['product_id'],
                    'combination_id'   => $option['xcombination']
                );
                if (!isset($products_by_combination[$option['xcombination']])) {
                    $products_by_combination[$option['xcombination']] = array();
                }
                $products_by_combination[$option['xcombination']][] = $product['product_id'];
            }
        }
        $this->cart_products = $cart_products;
        $this->cart_combinations = $cart_combinations;
        $this->products_by_combination = $products_by_combination;
    }
    private function deleteCartByCartId($cart_id) {
        $this->ocm->deleteCartProduct($cart_id);
        unset($this->cart_combinations[$cart_id]);
    }
    private function deleteCartByCombinationId($combination_id) {
        foreach ($this->cart_combinations as $cart_id => $cart_combination) {
            if ($combination_id == $cart_combination['combination_id']) {
                $this->deleteCartByCartId($cart_id);
            }
        }
        if (!empty($this->session->data['xc_skip'])) {
            $key = array_search($combination_id, $this->session->data['xc_skip']);
            if ($key !== false) {
                unset($this->session->data['xc_skip'][$key]);
            }
        }
    }
    private function clearCart() {
        $cart_combinations = $this->cart_combinations;
        while ($cart_combinations) {
            $cart_combination = array_pop($cart_combinations);
            $cart_id          = $cart_combination['cart_id'];
            $combination_id   = $cart_combination['combination_id'];
            $quantity         = $cart_combination['quantity'];
            $xcombination     = $this->getCombinationById($combination_id);
            if (!$xcombination) {
                $this->deleteCartByCartId($cart_id);
                continue;
            }
            $rules = isset($xcombination['rules']['combination']) ? $xcombination['rules']['combination']['value'] : array();

            $total_avail_set      = $this->getTotalOfferSet($rules);
            $total_avail_offers   = $this->getOfferLimit($xcombination, $total_avail_set);
            $total_offers_in_cart = $this->getTotalOfferInCart($combination_id);
            //echo $total_offers_in_cart .' vs ' . $total_avail_offers .'  ';
            if ($total_offers_in_cart > $total_avail_offers) {
                $this->deleteCartByCombinationId($combination_id);
            } else {
                $is_skipped = !empty($this->session->data['xc_skip']) && in_array($combination_id, $this->session->data['xc_skip']);
                if (!$is_skipped && $total_avail_offers > $total_offers_in_cart) {
                    $this->__pending[$combination_id] = array(
                        'limit'     => $total_avail_offers - $total_offers_in_cart,
                        'products'  => array($cart_combination['product_id'])
                    );
                }
                for ($i = 0; $i < $total_avail_set; $i++) { 
                    $this->syncCartProducts($combination_id);
                }
                // don't need to check same combination again so remove them
                foreach ($cart_combinations as $_cart_id => $_cart_combination) {
                    if ($_cart_combination['combination_id'] == $combination_id) {
                        unset($cart_combinations[$_cart_id]);
                    }
                }
            }
        }
    }
    private function isRuleValidInCart($rule, $type, $cart_products, $fulfilled = 0) {
        $status     = false;
        $cart_id    = 0;
        $product_id = 0;
        $suggestion = false; 
        foreach ($cart_products as $cart_product) {
            $cart_id    = $cart_product['cart_id'];
            if ($type == 'category' || $type == 'option_value') {
                $status = $this->array_intersect_faster($rule[$type], $cart_product[$type]);
                // if any bound option rule finds, validate it as well
                if ($status && !empty($rule['options'])) {
                    $status = $this->array_intersect_faster($rule['options'], $cart_product['option_value']);
                }
            } else {
                $status = in_array($cart_product[$type], $rule[$type]);
            }
            if ($status && $rule['quantity'] && $rule['quantity'] > $cart_product['quantity']) {
                if (($cart_product['quantity'] || $fulfilled) && $rule['quantity'] - $cart_product['quantity'] == 1) {
                    $suggestion = true;
                    $product_id = $cart_product['product_id']; // specifiy suggested products
                }
                $status = false;
            }
            if ($status) {
                $product_id = $cart_product['product_id']; // set gift products
                break;
            }
        }
        return array(
            'status'      => $status,
            'cart_id'     => $cart_id,
            'suggestion'  => $suggestion,
            'product_id'  => $product_id
        );
    }
    private function isRulesValidInCart($rules, $cart_products) {
        $status = true;
        $products = array();
        $fulfilled = 0;
        $suggestion = 0;
        foreach ($rules as $rule) {
            $type = $this->getRuleType($rule);
            if ($type) {
                $result = $this->isRuleValidInCart($rule, $type, $cart_products, $fulfilled);
                if (!$result['status']) {
                    $status = false;
                    $suggestion = $result['suggestion'] ? $result['product_id'] : $suggestion;
                    break;
                }
                $fulfilled++;
                $cart_products = $this->decreaseRuleQnty($rule, $result['cart_id'], $cart_products);
                $products[]    = $result['product_id'];
            }
        }
        if (count($rules) - $fulfilled > 1) {
            $suggestion = 0;
        }
        return array(
            'status'        => $status,
            'products'      => $products,
            'suggestion'    => $suggestion,
            'cart_products' => $cart_products
        );
    }
    private function decreaseRuleQnty($rule, $cart_id, $cart_products) {
        if (isset($cart_products[$cart_id])) {
            $cart_products[$cart_id]['quantity'] -= $rule['quantity'];
        }
        return $cart_products;
    }
    private function getTotalOfferSet($rules) {
        $no_of_set = 0;
        $status    = !!$rules && is_array($rules);
        $cart_products = $this->cart_products;
        while ($status) {
            $result = $this->isRulesValidInCart($rules, $cart_products);
            if ($result['status']) {
                $no_of_set++;
                $cart_products = $result['cart_products'];
            } else {
                break;
            }
        }
        return $no_of_set;
    }
    private function getTotalOfferInCart($combination_id) {
        $total = 0;
        foreach ($this->cart_combinations as $cart_combination) {
            if ($cart_combination['combination_id'] == $combination_id) {
                $total += $cart_combination['quantity'];
            }
        }
        return $total;
    }
    private function syncCartProducts($combination_id) {
        $cart_products = $this->cart_products;
        $xcombination = $this->getCombinationById($combination_id);
        $rules = isset($xcombination['rules']['combination']) ? $xcombination['rules']['combination']['value'] : array();
        foreach ($rules as $rule) {
            $type = $this->getRuleType($rule);
            if ($type) {
                $result = $this->isRuleValidInCart($rule, $type, $cart_products);
                if ($result['status']) {
                     $cart_products = $this->decreaseRuleQnty($rule, $result['cart_id'], $cart_products);
                }
            }
        }
        $this->cart_products = $cart_products;
    }
    public function getRuleType($rule) {
        $type = '';
        foreach ($this->types as $type) {
            if (isset($rule[$type]) && is_array($rule[$type])) {
                break;
            }
        }
        return $type;
    }
    private function _validateCombination($rules) {
        $cart_products = $this->cart_products;
        $is_valid      = false;
        $result        = $this->isRulesValidInCart($rules, $cart_products);
        if ($result['status']) {
            $is_valid = true;
            $this->__products = $result['products'];
        } else if ($result['suggestion']) {
            $this->__require = array(
                'product_id'      => $result['suggestion'],
                'option_value_id' => 0
            );
        }
        return $is_valid;
    }
    public function isCartCombination($combination_id) {
        $status = false;
        foreach ($this->cart_combinations as $cart_combination) {
            if ($cart_combination['combination_id'] == $combination_id) {
                $status = true;
                break;
            }
        }
        return $status;
    }
    public function isCombinationProduct($rules, $product) {
        $is_found = false;
        foreach ($rules as $rule) {
            $type = $this->getRuleType($rule);
            if ($type) {
               $is_found = $this->array_intersect_faster($rule[$type], $product[$type]);
               if ($is_found) {
                    break;
               }
            }
        }
        return (boolean)$is_found;
    }
    public function getCheapestOrExpensive($products, $mode = 'cheapest') {
        $ids = array_keys($products);
        $rows = $this->db->query("SELECT product_id, price FROM " . DB_PREFIX . "product WHERE product_id IN (".implode(',', $ids).")")->rows;
        $value = 0;
        $return = 0;
        foreach ($rows as $row) {
            $special = $this->db->query("SELECT price FROM " . DB_PREFIX . "product_special WHERE product_id = '" . (int)$row['product_id'] . "' AND customer_group_id = '" . (int)$this->config->get('config_customer_group_id') . "' AND ((date_start = '0000-00-00' OR date_start < NOW()) AND (date_end = '0000-00-00' OR date_end > NOW())) ORDER BY priority ASC, price ASC LIMIT 1")->row;
            if ($special && $special['price']) {
                $row['price'] = $special['price'];
            }
            if ($mode == 'cheapest') {
                if ($row['price'] < $value || !$value) {
                    $return = $row['product_id'];
                    $value = $row['price'];
                }
            } else {
                if ($row['price'] > $value) {
                    $return = $row['product_id'];
                    $value = $row['price'];
                }
            }
        }
        return $return;
    }
}