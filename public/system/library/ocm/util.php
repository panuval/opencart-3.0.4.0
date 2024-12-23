<?php
namespace OCM;
final class Util {
    private $registry;
    private $ocm_meta;
    private $event;
    private $modification;
    private $startup;
    private $ocm_events;
    private $ocm_startups;
    public function __construct($registry, $meta) {
        $this->registry = $registry;
        $this->ocm_meta = $meta;
        $setting_ext = (VERSION >= '3.0.0.0') ? 'setting' : 'extension';
        
        if (VERSION >= '2.0.1.1') {
            $this->load->model($setting_ext . '/event');
            $this->event = $this->{'model_' . $setting_ext . '_event'};
        }
        $this->modification = false;
        if (VERSION < '4.0.0.0') {
            $this->load->model($setting_ext . '/modification');
            $this->modification = $this->{'model_' . $setting_ext . '_modification'};
        }
        if (VERSION >= '4.0.0.0') {
            $this->load->model($setting_ext . '/startup');
            $this->startup = $this->{'model_' . $setting_ext . '_startup'};
        }
        if (VERSION <= '2.2.0.0') {
            $prefix = '';
        } else if (VERSION <= '3.9.9.9') {
            $prefix = 'extension/';
        } else {
            $prefix = 'extension/' . $this->ocm_meta['name'] . '/';
        }
        $this->ocm_events = array(
            array(
                'trigger' => 'catalog/view/*/after',
                'action'  => $prefix . 'module/ocm/onViewAfter'
            ),
            array(
                'trigger' => 'catalog/model/checkout/order/add'. (VERSION < '4.0.0.0' ? 'Order' : '') . 'History/after',
                'action'  => $prefix . 'module/ocm/onOrderHistory'
            ),
            array(
                'trigger' => 'catalog/model/' . $setting_ext . '/extension/' . (VERSION < '4.0.0.0' ? 'getExtensions' : 'getExtensionsByType') . '/after',
                'action'  => $prefix . 'module/ocm/onExtensions'
            ),
            array(
                'trigger' => 'catalog/model/*/product/*/after',
                'action'  => $prefix . 'module/ocm/onProductAfter'
            ),
            array(
                'trigger' => 'admin/view/*/before',
                'action'  => $prefix . 'module/ocm/onViewBefore'
            ),
            array(
                'trigger' => 'admin/view/*/after',
                'action'  => $prefix . 'module/ocm/onViewAfter'
            )
        );

        /* usually one should be fine but let's keep for future extensibility */
        $this->ocm_startups = array(
            array(
                'action'  => 'catalog/' . $prefix . 'module/ocm.onStartup'
            )
        );
    }
    public function __get($name) {
        return $this->registry->get($name);
    }
    public function addEvents($events, $code = '') {
        if (VERSION < '2.2.0.0') return false;
        if (!$code) {
            $code = $this->ocm_meta['name'];
        }
        $this->deleteEvents($code);
        foreach ($events as $event) {
            $event['action'] = $this->applyMethodDivider($event['action']);
            if (strpos($event['trigger'], 'controller') !== false) {
                $event['trigger'] = $this->applyMethodDivider($event['trigger']); // v4, for controller, it needs to add | or . as method divider
            }
            if (VERSION == '4.0.0.0') {
                $description = '';
                $this->event->addEvent($this->ocm_meta['name'], $description , $event['trigger'], $event['action']);
            } else if (VERSION >= '4.0.1.0') {
                $data = array();
                $data['code'] = $this->ocm_meta['name'];
                $data['description'] = '';
                $data['trigger'] = $event['trigger'];
                $data['action'] = $event['action'];
                $data['status'] = true;
                $data['sort_order'] = 1;
                $this->event->addEvent($data);
            } else {
                $this->event->addEvent($this->ocm_meta['name'], $event['trigger'], $event['action']);
            }
        }
    }
    public function deleteEvents($code = '') {
        if (!$code) {
            $code = $this->ocm_meta['name'];
        }
        if (VERSION >= '3.0.0.0') {
            $this->event->deleteEventByCode($code);
        } else {
            $this->event->deleteEvent($code);
        }
    }
    public function addStartup() {
        if (VERSION < '4.0.0.0') return false;
        $discountModules = array("xdiscount", "xcombination", "xlevel", "xgift", "xbundle");
        if (!in_array($this->ocm_meta['name'], $discountModules)) return false;
        $ocm_startup = $this->startup->getStartupByCode('ocm');
        if (!$ocm_startup) {
            foreach($this->ocm_startups as $startup) {
                $data = array();
                $data['code'] = 'ocm';
                $data['action'] = $startup['action'];
                $data['status'] = true;
                $data['sort_order'] = 1;
                $this->startup->addStartup($data);
            }
        }
    }
    public function safeDBColumnAdd($tables = array()) {
        foreach($tables as $table => $columns) {
            foreach($columns as $column) {
                if (!$this->db->query("SELECT * FROM information_schema.columns WHERE table_schema = '" . DB_DATABASE . "' AND table_name = '" . DB_PREFIX . $table . "' and column_name='" . $column['name'] . "' LIMIT 1")->row) {
                    $this->db->query("ALTER TABLE `" . DB_PREFIX . $table . "` ADD `" . $column['name'] . "` " . $column['option']); 
                }
            }
        }
    }
    public function isDBBUpdateAvail($tables = array(), $events = array()) {
        // it create issue in case of latency mijoshop so ignore
        if (defined('_JEXEC') && VERSION < '3.0.0.0') { 
            return false;
        }
        $db_status = false;
        foreach($tables as $table => $columns) {
            if(!$this->db->query("SELECT * FROM information_schema.tables WHERE table_schema = '" . DB_DATABASE . "' AND table_name = '" . DB_PREFIX . $table . "' LIMIT 1")->row) {
                $db_status = true;
                break;
            }
            foreach($columns as $column) {
                if (!$this->db->query("SELECT * FROM information_schema.columns WHERE table_schema = '" . DB_DATABASE . "' AND table_name = '" . DB_PREFIX . $table . "' and column_name='" . $column['name'] . "' LIMIT 1")->row){
                   $db_status = true;
                   break;
                }
            }
        }
        $event_status = false;
        if (VERSION >= '2.2.0.0') {
            $rows = $this->db->query("SELECT DISTINCT `trigger` FROM `" . DB_PREFIX . "event` WHERE `code` = '" . $this->ocm_meta['name'] . "'")->rows;
            $existing_events = array();
            foreach ($rows as $key => $value) {
                $existing_events[] = $value['trigger'];
            }
            $event_status = $this->isEventChanged($events, $existing_events);
        }
        /* common core libraray for all modules */
        // remove installed file list for OC 3.x
        if (VERSION >= '3.0.0.0') {
            $ocm_path = VERSION >= '4.0.0.0' ? '%/system/storage/ocm%' : 'system/library/ocm%';
            $this->db->query("DELETE FROM `" . DB_PREFIX . "extension_path` WHERE `path` LIKE '".$ocm_path."'");
        }
        // install common event if not exist
        $is_ocm_event = !empty($this->ocm_meta['event']) && $this->ocm_meta['event'];
        if ($is_ocm_event && VERSION >= '2.2.0.0') {
            $rows = $this->db->query("SELECT `trigger` FROM `" . DB_PREFIX . "event` WHERE `code` = 'ocm'")->rows;
            $existing_events = array();
            foreach ($rows as $key => $value) {
                $existing_events[] = $value['trigger'];
            }
            if ($this->isEventChanged($this->ocm_events, $existing_events)) {
                $this->db->query("DELETE FROM `" . DB_PREFIX . "event` WHERE `code` = 'ocm'");
                foreach ($this->ocm_events as $event) {
                    $event['action'] = $this->applyMethodDivider($event['action']);
                    if (VERSION == '4.0.0.0') {
                        $this->event->addEvent('ocm', '', $event['trigger'], $event['action']);
                    } else if (VERSION >= '4.0.1.0') {
                        $data = array();
                        $data['code'] = 'ocm';
                        $data['description'] = '';
                        $data['trigger'] = $event['trigger'];
                        $data['action'] = $event['action'];
                        $data['status'] = true;
                        $data['sort_order'] = 1;
                        $this->event->addEvent($data);
                    } else {
                        $this->event->addEvent('ocm', $event['trigger'], $event['action']);
                    }
                }
            }
        }

        // update startup
        $this->addStartup();

        return array(
            'db'    => $db_status,
            'event' => $event_status
        );
    }
    public function removeDBTables($tables = array()) {
        foreach($tables as $table => $columns) {
            $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . $table . "`");
        }
    }
    public function isEventChanged($new, $old) {
        $status = false;
        if (count($new) != count($old)) {
            $status = true;
        } else {
            foreach ($new as $event) {
                if (!in_array($event['trigger'], $old)) {
                    $status = true;
                }
            }
        }
        return $status;
    }
    private function applyMethodDivider($input) {
        if (VERSION >= '4.0.0.0') {
             $divider = VERSION >= '4.0.0.0' ? (VERSION >= '4.0.2.0' ? '.' : '|') : '/';
             $parts = explode('/', $input);
             $modifier = '';
             $method = array_pop($parts);
             if ($method == 'before' || $method == 'after') {
                $modifier = '/' . $method;
                $method = array_pop($parts);
             }
             $input = implode('/', $parts) . $divider . $method . $modifier;
        }
        return $input;
    }
}