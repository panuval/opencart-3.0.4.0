<?php 
namespace OCM\Lib;
use OCM as OcmCore; 
final class XBundle {
    private $registry;
    private $ocm;
    private $mtype;
    private $xbundle_text = 'Bundled';
    private $flexibility = false;
    public function __construct($registry) {
        $this->registry = $registry;
        $this->ocm = ($ocm = $this->registry->get('ocm_front')) ? $ocm : new OcmCore\Front($this->registry);
        $this->mtype = 'module';
        $language_id = $this->config->get('config_language_id');
        $xbundle_text = $this->ocm->getConfig('xbundle_text', $this->mtype);
        $this->flexibility = $this->ocm->getConfig('xbundle_flexibility', $this->mtype);
        if (isset($xbundle_text[$language_id])) {
            $this->xbundle_text = $xbundle_text[$language_id];
        }
    }
    public function __get($name) {
       return $this->registry->get($name);
    }
    public function applyCartPrice($data) {
       // only consider OC default special price, not from other modules, it may create issues
       return $this->getBundlePrice($data['cart'], $data['product'], $data['special'], $data['option_data']);
    }
    public function getBundlePrice($cart, $product, $special, $option_data) {
        $return = array();
        $bundle_id = (int)$cart['product_id'];
        $option = $cart['option'];

        if ($this->ocm->isAdmin()) {
            foreach ($option as $key => $value) {
                if (!is_array($value)) {
                  $value = $this->ocm->html_decode($value);
                  if (strpos($value, '<xb>') !== false) {
                     $option['xbundle'] = $key;
                  }
                }
            }
        }
        if (!isset($option['xbundle']) || !$option['xbundle'] || (int)$option['xbundle'] === $bundle_id) {
            return false;
        }

        $this->load->model('extension/module/xbundle');
        $bundle_info = $this->model_extension_module_xbundle->getBundleProduct($option['xbundle'], $bundle_id);
        if ($bundle_info) {
            $original_price = !empty($special) ? (float)$special : (float)$product['price'];
            $return['price'] = $this->model_extension_module_xbundle->getDiscountedPrice($bundle_info['discount'], $original_price);
            $option_data[] = array(
                'product_option_id'       => $option['xbundle'],
                'product_option_value_id' => $option['xbundle'],
                'option_id'               => $option['xbundle'],
                'option_value_id'         => $option['xbundle'],
                'name'                    => '',
                'value'                   => '<xb>' . $this->xbundle_text . '</xb>',
                'type'                    => 'text',
                'price'                   => 0,
                'price_prefix'            => '+'
            );
            $return['option_data'] = $option_data;
        }
        return $return ? $return : false;
    }

    /* this is needed as native update increase quanity if found same product */
    public function updateCartOption($cart_id, $option) {
        $this->ocm->updateCartOption($cart_id, $option);
    }
    public function isBundleExist($_product_id) {
        $return = false;
        $rows = $this->ocm->getCartProducts();
        foreach ($rows as $cart) {
           if ((int)$cart['product_id'] != $_product_id) continue;
           $option = $cart['option'];
           if (isset($option['xbundle']) && $option['xbundle'] == $_product_id) {
              $return = array(
                 'option' => $option,
                 'cart_id' => $cart['cart_id']
              );
              break;
           }
        }
        return $return;
    }
    public function isBundleProductExist($bundle_id, $_product_id) {
        $return = false;
        $rows = $this->ocm->getCartProducts();
        foreach ($rows as $cart) {
           if ((int)$cart['product_id'] != $bundle_id) continue;
           $option = $cart['option'];
           if (isset($option['xbundle']) && $option['xbundle'] == $_product_id) {
              $return = array(
                 'option' => $option,
                 'cart_id' => $cart['cart_id']
              );
              break;
           }
        }
        return $return;
    }

    /* 
      Return 0 : It is a bundle product
      Return > 0 : it is bundle main product
      Return < 0 : It is not non-bundle product 
    */
    private function getStatusByCartId($cart_id) {
        $cart = $this->ocm->getCartProductById($cart_id);
        if (!$cart || !isset($cart['option'])) {
            return -1;
        }
        $option = $cart['option'];
        $_product_id = (int)$cart['product_id'];

        if (isset($option['xbundle']) && $option['xbundle'] == $_product_id) {
            return $_product_id;
        }
        if (isset($option['xbundle'])) {
           return 0;
        }
        return -1;
    }

    public function onCartUpdate($cart_id) {
        if ($this->flexibility) return false;
        return $this->getStatusByCartId($cart_id) === 0 ? array('stop' => true) : false;
    }
    public function onCartRemove($cart_id) {
        $_product_id = $this->getStatusByCartId($cart_id);
        if ($_product_id > 0) {
            $rows = $this->ocm->getCartProducts();
            foreach ($rows as $cart) {
                $option = $cart['option'];
                if (isset($option['xbundle']) && $option['xbundle'] == $_product_id) {
                    $this->ocm->deleteCartProduct($cart['cart_id']);
                }
            }
        }
        return false;
    }
}