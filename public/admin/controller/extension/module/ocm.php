<?php
class ControllerExtensionModuleOcm extends Controller {
    protected $registry;
    private $ocm;
    public function __construct($registry) {
        parent::__construct($registry);
        $meta = array('name' => 'common', 'type' => 'module');
        $this->registry = $registry;
        $this->ocm = $this->registry->has('ocm_back') ? $this->registry->get('ocm_back') : new OCM\Back($this->registry, $meta);
    }
    public function onViewBefore($route, &$data) {
        // set api token if available
        if (!$this->ocm->getCache('api_token')) {
            if (!empty($data['api_token'])) {
                $this->ocm->setCache('api_token', '&api_token=' . $data['api_token']);
            }
            else if (!empty($data['user_token'])) {
                $this->ocm->setCache('api_token', '&user_token=' . $data['user_token']);
            } 
            // need to work for OC <= 2.3
        }

        // hide ocm modules
        if (!empty($data['extensions']) && is_array($data['extensions']) && count($data['extensions']) > 0) {
            foreach ($data['extensions'] as $index => $extension) {
                if (!empty($extension['install']) 
                    && $extension['install'] 
                    && (strpos($extension['install'], 'code=ocm') !== false 
                        || strpos($extension['install'], 'extension=ocm') !== false
                        || preg_match('/extension=xpayment\d/m', $extension['install'], $matches, PREG_OFFSET_CAPTURE, 0)))  {
                    unset($data['extensions'][$index]);
                }
            }
        }     

        if ($route == 'common/column_left') {
            $data = $this->ocm->getAdminMenu($data);
        }

        /* enabling only for desired route to avoid unnecessary event processing */
        if ($route == 'catalog/product_form'
            || $route == 'sale/order_list'
            || $route == 'sale/order_form'
            || $route == 'sale/order_info') {
            $data = $this->ocm->onPrimaryViewBefore($route, $data);
        }
    }
    public function onViewAfter($route, $input, &$output) {
        if ($route == 'common/footer') {
            $output = $this->ocm->getScript() . $output;
        
            // set api token to access catalog api
            if (!$this->ocm->getCache('api_token_set') && $this->ocm->isCacheAvail('api_token')) {
                $api_output = '<script type="text/javascript">';
                $api_output .= 'var _ocm_api_token = "'. $this->ocm->getCache('api_token') .'";';
                $api_output .= '</script>';
                if (strpos($output, '</body>') !== false) {
                    $output = str_replace('</body>', $api_output . '</body>', $output);
                } else {
                    $output = $output . $api_output;
                }
                $this->ocm->setCache('api_token_set', true);
            }
        }

        /* enabling only for desired route to avoid unnecessary event processing */
        if ($route == 'catalog/product_form'
            || $route == 'sale/order_list'
            || $route == 'sale/order_form'
            || $route == 'sale/order_info') {
            $output = $this->ocm->onPrimaryViewAfter($route, $input, $output);
        }    
    }
}