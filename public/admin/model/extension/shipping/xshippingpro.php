<?php
class ModelExtensionShippingXshippingpro extends Model {
    use OCM\Traits\Back\Model\Crud;
    use OCM\Traits\Back\Model\Product;
    private $name = 'xshippingpro';
    public function addDBTables() {
        $sql = "
            CREATE TABLE IF NOT EXISTS `".DB_PREFIX."xshippingpro` (
              `id` int(8) NOT NULL AUTO_INCREMENT,
              `method_data` MEDIUMTEXT NULL,
              `tab_id` int(8) NULL,
              `sort_order` int(8) NULL,
               PRIMARY KEY (`id`)
            ) ENGINE=MyISAM  DEFAULT CHARSET=utf8;
        ";
        $query = $this->db->query($sql);
    }
    public function getScript($ocm) {
        $html = '';
        $route = $ocm->getRoute();
        $ocm_base_url = $ocm->common->getOcmBaseUrl('admin', $this->name);
        if ($route['path'] == 'sale/order' && ($route['method'] == 'info' || $route['method'] == 'edit')) {
            $html .= '<script src="' . $ocm_base_url . 'view/javascript/xshippingpro.js?v=5.0.1" defer type="text/javascript"></script>';
        }
        return $html;
    }

    public function onAdminMenu($ocm, $data) {
        $menu = array(
            'id'       => 'menu-xshipingpro',
            'icon'     => 'fa fa-truck"', 
            'name'     => 'X-Shippingpro',
            'href'     => $ocm->url->getExtensionURL(),
            'children' => array()
        );

        // find a child menu if available, otherwise adds to the main menu
        $dest_menu = $ocm->misc->getMenuIndex('menu-extension', $data['menus']);
        if ($dest_menu >= 0) {
            array_push($data['menus'][$dest_menu]['children'], $menu); 
        } else {
           array_push($data['menus'], $menu); 
        } 
        return $data;
    }
}