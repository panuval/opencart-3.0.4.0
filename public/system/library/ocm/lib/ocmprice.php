<?php
namespace OCM\Lib;
final class Ocmprice {
    private $registry;
    private $xbundle = false;
    private $xgift = false;
    private $xdiscount = false;
    private $xlevel = false;
    private $bulkprice = false;
    private $xcombination = false;
    private $_xproducts_flag = true;
    private $_on_carts_flag = true;
    private $_xproducts = array();
    private $_sp_products_cache = array(); /* Cache speical price 1. Performace issue 2. infinity Loop invoking issue */
    private $_cart_cache = array(
        'xdiscount' => array(),
        'xlevel'    => array()
    );

    public function __construct($registry) {
        $this->registry = $registry;
        $prefix = VERSION >= '3.0.0.0' ? 'module_' : '';
        if ($this->config->get($prefix . 'xbundle_status')) {
            $this->xbundle = new Xbundle($registry);
            $registry->set('xbundle', $this->xbundle);
        }
        if ($this->config->get($prefix . 'xgift_status')) {
            $this->xgift = new Xgift($registry);
            $registry->set('xgift', $this->xgift);
        }
        if ($this->config->get($prefix . 'xdiscount_status')) {
            $this->xdiscount = new Xdiscount($registry);
            $registry->set('xdiscount', $this->xdiscount);
        }
        if ($this->config->get($prefix . 'xlevel_status')) {
            $this->xlevel = new Xlevel($registry);
            $registry->set('xlevel', $this->xlevel);
        }
        if ($this->config->get($prefix . 'bulkprice_status')) {
            $this->bulkprice = new Bulkprice($registry);
            $registry->set('bulkprice', $this->bulkprice);
        }
        if ($this->config->get($prefix . 'xcombination_status')) {
            $this->xcombination = new Xcombination($registry);
            $registry->set('xcombination', $this->xcombination);
        }
    }

    public function __get($name) {
       return $this->registry->get($name);
    }
    /* price prefix for OC 2.1.x */
    public function trigger($data) {
        if ($this->xlevel && $price_prefix = $this->xlevel->getPricePrefix()) {
            $path = (VERSION >= '2.3.0.0') ? 'extension/module/' : 'module/';
            $key = 'model_' . str_replace('/', '_', $path) . 'xlevel';
            $this->load->model($path . 'xlevel');
            $data = $this->{$key}->applyPricePrefix($data, $price_prefix);
        }
        return $data;
    }
    public function onCartOperation($cart_id, $fn) {
        $return = false;
        $modules = array('xgift', 'xbundle', 'xcombination');
        $method = 'onCart' . ucfirst($fn);
        foreach ($modules as $key) {
            if ($this->{$key} && method_exists($this->{$key}, $method)) {
                $_return = $this->{$key}->{$method}($cart_id);
                if ($_return) {
                    $return = $_return;
                }
                $this->_on_carts_flag = true; // set true so it can re-process onCartProducts
            }
        }
        return $return;
    }
    public function onCartProducts() {
        if (!$this->_on_carts_flag) return false;
        $modules = array('xgift', 'xbundle', 'xcombination');
        foreach ($modules as $key) {
            if ($this->{$key} && method_exists($this->{$key}, 'onCartProducts')) {
                $this->{$key}->{'onCartProducts'}();
            }
        }
        $this->_on_carts_flag = false;
    }
    public function applyCartPrice($data) {
        if (VERSION >= '2.1.0.0') {
            $data['cart']['option'] = json_decode($data['cart']['option'], true);
        }
        if (!isset($data['cart']['option']) || !is_array($data['cart']['option'])) {
            $data['cart']['option'] = array();
        }
        if (isset($data['reward'])) {
            $data['cart']['reward'] = $data['reward'];
        }
        $is_xpriced = isset($data['cart']['option']['xgift']) || isset($data['cart']['option']['xbundle']) || isset($data['cart']['option']['xcombination']);
        if ($data['special'] && !$is_xpriced) {
            $this->_sp_products_cache[$data['product']['product_id']] = $data['special'] + $data['option_price'];
        }
        // sync stock if same product is added in cart for xbundle and xgift i.e option based modification
        // TODO - OC < 2.1.x
        if ($this->_xproducts_flag) {
            $this->setXProductStock($data['carts']);
            $this->_xproducts_flag = false;
        }
        if (!$is_xpriced && isset($this->_xproducts[$data['cart']['product_id']])) {
            $data['product']['quantity'] -= $this->_xproducts[$data['cart']['product_id']];
            //$this->_xproducts[$data['cart']['product_id']] = 0;
        }
        // end of stock sync
        $data['product']['special'] = $data['special'];
        $data['product']['discount'] = $data['discount'];
        $data['product']['ocm_price'] = $data['product']['price'];
        $option_cache_key = json_encode($data['cart']['option']);

        $modules = array('bulkprice', 'xdiscount', 'xlevel', 'xcombination', 'xgift', 'xbundle');
        foreach ($modules as $key) {
            if ($this->{$key} && method_exists($this->{$key}, 'applyCartPrice')) {
                $_return = $this->{$key}->{'applyCartPrice'}($data);
                if ($_return !== false) {
                    $cache_key = $data['product']['product_id'] . '_' . $option_cache_key;
                    // let's cache the result so other module can use it
                    if (isset($this->_cart_cache[$key]) && isset($_return['amount'])) {
                        $applied = array(
                            'discount'     => $_return['amount'],
                            'amount'       => $_return['amount'] * $data['cart']['quantity'],
                            'tax_class_id' => $data['product']['tax_class_id'],
                            'product_id'   => $data['product']['product_id'],
                            'on_total'     => isset($_return['on_total'])
                        );
                        if (isset($_return['option_amount'])) {
                            $applied['amount'] += $_return['option_amount'] * $data['cart']['quantity'];
                        }
                        if (isset($_return['type'])) {
                            $applied['type'] = $_return['type'];
                        }
                        $this->_cart_cache[$key][$cache_key] = $applied;
                    }
                    // don't apply on cart if it is order-discount
                    if (isset($this->_cart_cache[$key]) && isset($_return['on_total'])) {
                        $data['price'] = $data['product']['ocm_price']; // reset to original price
                        $data['product']['price'] = $_return['price'];
                        /* when it shows as `Order Total `, it will create disparity so let set special price as the main price */
                        if ($key == 'xlevel' && !empty($data['product']['special'])) {
                            $data['price'] = $data['product']['special']; 
                        }
                        // when qnty discount applies to special price
                        if ($key == 'xdiscount' && isset($_return['reset_with']) && $_return['reset_with'] == 'special' && !empty($data['product']['special'])) {
                            $data['price'] = $data['product']['special']; 
                        }
                        continue;
                    }
                    if (isset($_return['price'])) {
                        $data['price'] = $_return['price'];
                        $data['product']['price'] = $_return['price'];
                        if (isset($_return['type']) && $_return['type'] == 'overwrite') {
                            $data['product']['ocm_price'] = $_return['price'];    
                        }
                        // remove discount cache, otherwise will create double discount in case of order-discount
                        if (in_array($key, array('xcombination', 'xgift', 'xbundle'))) {
                            foreach(array_keys($this->_cart_cache) as $_key) {
                               if (isset($this->_cart_cache[$_key][$cache_key])) {
                                    unset($this->_cart_cache[$_key][$cache_key]); 
                               }
                            }
                        }
                    }
                    if (isset($_return['option_price'])) {
                        $data['option_price'] = $_return['option_price'];
                    }
                    if (isset($_return['option_data'])) {
                        $data['option_data'] = $_return['option_data'];
                    }
                    if (isset($_return['reward'])) {
                        $data['reward'] = $_return['reward'];
                    }
                    if (isset($_return['name'])) {
                        $data['product']['name'] = $_return['name'];
                    }
                    // finish process if no_return is set
                    if (!isset($_return['no_return'])) {
                        return;
                    }
                }
            }
        }
    }
    public function getDiscountedProduct($data) {
        if (!isset($data['product']['ocm_price'])) {
            $data['product']['ocm_price'] = $data['product']['price'];
        }
        $modules = array('bulkprice', 'xdiscount', 'xlevel');
        foreach ($modules as $key) {
            if ($this->{$key} && method_exists($this->{$key}, 'getDiscountedProduct')) {
                $_return = $this->{$key}->{'getDiscountedProduct'}($data['product']);
                if ($_return !== false) {
                    if ($_return['type'] == 'overwrite') {
                        if (isset($_return['price'])) {
                            $data['product']['price'] = $_return['price'];
                        }
                        if (isset($_return['reset']) && $_return['reset']) {
                            $data['product']['discount'] = 0;
                            $data['product']['special'] = NULL;
                        }
                        if (isset($data['product']['ocm_price']) && isset($_return['price'])) {
                            $data['product']['ocm_price'] = $_return['price'];
                        }
                        if (isset($_return['special'])) {
                            $data['product']['special'] = $_return['special'];
                        }
                        if (isset($_return['discount'])) {
                            $data['product']['discount'] = $_return['discount'];
                        }
                    }
                    else if ($_return['type'] == 'special') {
                        $data['product']['special'] = $_return['price'];
                        if (isset($_return['reset']) && $_return['reset']) {
                            $data['product']['discount'] = 0;
                        }
                    }
                    else if ($_return['type'] == 'discount') {
                        $data['product']['discount'] = $_return['price'];
                        $data['product']['price'] = $_return['price']; // overwrite price as it is discount price
                        if (isset($_return['reset']) && $_return['reset']) {
                            $data['product']['special'] = NULL;
                        }
                    }
                    // finish process if no_return is set
                    if (!isset($_return['no_return'])) {
                        return;
                    }
                }
            }
        }
    }
    public function applyOptionStrikethrough($data) {
        if ($data['type'] !== 'select'
            && !empty($data['option_value'])
            && isset($data['option_value']['ocm_line']) 
            && $data['option_value']['ocm_line'] 
            && isset($data['option_value']['ocm_price'])
            && !empty($data['price'])) {
                $old_price = $this->currency->format($this->tax->calculate($data['option_value']['ocm_price'], $data['tax_class_id'], $this->config->get('config_tax') ? 'P' : false), $this->session->data['currency']);
                $old_price = '<span class="xprice-option-line" style="text-decoration:line-through; margin-right: 5px;">' . $old_price . '</span>';
                $data['price'] = $old_price . $data['price'];
        }
    }
    // deprecated -- will remove later. New function applyOptionStrikethrough
    public function getDiscountedOption($data) {
        return $data; 
    }
    public function getOptionPrice($data) {
        $modules = array('bulkprice', 'xdiscount', 'xlevel');
        foreach ($modules as $key) {
            if ($this->{$key} && method_exists($this->{$key}, 'getOptionPrice')) {
                $price_prefix = isset($data['price_prefix']) ? $data['price_prefix'] : '+';
                $_return = $this->{$key}->{'getOptionPrice'}($data['price'], $data['product_id'], $price_prefix);
                if ($_return !== false) {
                    $data['price'] = $_return['price'];
                    if (isset($_return['strike_line']) && isset($data['ocm_line'])) {
                        $data['ocm_line'] = $_return['strike_line'];
                    }
                    if (isset($_return['overwrite']) && isset($data['ocm_price'])) {
                        $data['ocm_price'] = $data['price'];
                    }
                    // finish process if no_return is set
                    if (!isset($_return['no_return'])) {
                        return;
                    }
                }
            }
        }
    }
    public function getQuantityDiscount($data) {
        $modules = array('bulkprice');
        foreach ($modules as $key) {
            if ($this->{$key} && method_exists($this->{$key}, 'getQuantityDiscount')) {
                $_return = $this->{$key}->{'getQuantityDiscount'}($data['quantity']['price'], $data['quantity']['product_id']);
                if ($_return !== false) {
                    $data['quantity']['price'] = $_return['price'];
                    // finish process if no_return is set
                    if (!isset($_return['no_return'])) {
                        return;
                    }
                }
            }
        }
    }
    public function getCartSpecialProducts() {
        return $this->_sp_products_cache;
    }
    public function setXProductStock($carts) {
        $_xproducts = array();
        if (VERSION < '2.1.0.0') { // TODO OC < 2.1.x, adjust code like ocm/front.php getCartProducts
            return $_xproducts;
        }
        foreach ($carts as $cart) {
            $option = json_decode($cart['option'], true);
            if ($option && (isset($option['xbundle']) || isset($option['xgift']) || isset($option['xcombination']))) {
                if (!isset($_xproducts[$cart['product_id']])) $_xproducts[$cart['product_id']] = 0;
                $_xproducts[$cart['product_id']] += $cart['quantity'];
            }
        }
        $this->_xproducts = $_xproducts;
    }
    // deprecated - change to getDiscountedProducts at later time. will be used 'getCartDiscountValue' now
    public function getCartDiscount($type) {
        return isset($this->_cart_cache[$type]) ? $this->_cart_cache[$type] : array();
    }
    public function getCartDiscountValue($type, $calculate = true) {
        $products = isset($this->_cart_cache[$type]) ? $this->_cart_cache[$type] : array();
        $discount = 0;
        $tax = 0;
        $taxes = array();
        foreach ($products as $key => $product) {
            $discount += $product['amount'];
            if ($product['tax_class_id']) {
                if (!isset($taxes[$product['tax_class_id']])) {
                    $taxes[$product['tax_class_id']] = 0;
                }
                $tax_rates = $this->tax->getRates($product['amount'], $product['tax_class_id']);
                foreach ($tax_rates as $tax_rate) {
                    if (!isset($taxes[$tax_rate['tax_rate_id']])) {
                        $taxes[$tax_rate['tax_rate_id']] = $tax_rate['amount'];
                    } else {
                        $taxes[$tax_rate['tax_rate_id']] += $tax_rate['amount'];
                    }
                    $tax += $tax_rate['amount'];
                }
            }
        }
        if ($calculate) {
            return $discount + $tax;
        } else {
            return array('discount' => $discount, 'tax' => $tax, 'taxes' => $taxes);
        }
    }
    public function getXDiscountedProducts($id_only = true) {
        $return = array();
        foreach($this->_cart_cache as $each) {
            if (is_array($each)) {
                foreach($each as $product) {
                    $return[$product['product_id']] = $id_only ? $product['product_id'] : $product;
                }
            }
        }
        return $return;
    }
}