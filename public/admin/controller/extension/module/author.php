<?php
//==============================================
// Author Module
// Author 	: OpenCartBoost
// Email 	: support@opencartboost.com
// Website 	: http://www.opencartboost.com
//==============================================
class ControllerExtensionModuleAuthor extends Controller {
	private $error = array();

	public function index() {
		$this->load->language('extension/module/author');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/module');

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			if (!isset($this->request->get['module_id'])) {
				$this->model_setting_module->addModule('author', $this->request->post);
			} else {
				$this->model_setting_module->editModule($this->request->get['module_id'], $this->request->post);
			}

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
		}

		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else {
			$data['error_warning'] = '';
		}
		
		if (isset($this->error['name'])) {
			$data['error_name'] = $this->error['name'];
		} else {
			$data['error_name'] = '';
		}
		
		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
		);
		
		if (!isset($this->request->get['module_id'])) {
			$data['breadcrumbs'][] = array(
				'text' => $this->language->get('heading_title'),
				'href' => $this->url->link('extension/module/author', 'user_token=' . $this->session->data['user_token'], true)
			);
		} else {
			$data['breadcrumbs'][] = array(
				'text' => $this->language->get('heading_title'),
				'href' => $this->url->link('extension/module/author', 'user_token=' . $this->session->data['user_token'] . '&module_id=' . $this->request->get['module_id'], true)
			);
		}
		
		if (!isset($this->request->get['module_id'])) {
			$data['action'] = $this->url->link('extension/module/author', 'user_token=' . $this->session->data['user_token'], true);
		} else {
			$data['action'] = $this->url->link('extension/module/author', 'user_token=' . $this->session->data['user_token'] . '&module_id=' . $this->request->get['module_id'], true);
		}

		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

		$data['user_token'] = $this->session->data['user_token'];
		
		$query_book_detail = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "product` LIKE 'book_language'");
		
		if ($query_book_detail->num_rows) {
			$data['upgrade'] = $this->url->link('extension/module/author/upgrade', 'user_token=' . $this->session->data['user_token'], true);
		} else {
			$data['upgrade'] = false;
		}
		
		if (isset($this->request->get['module_id']) && ($this->request->server['REQUEST_METHOD'] != 'POST')) {
			$module_info = $this->model_setting_module->getModule($this->request->get['module_id']);
		}	
			
		if (isset($this->request->post['name'])) {
			$data['name'] = $this->request->post['name'];
		} elseif (!empty($module_info)) {
			$data['name'] = $module_info['name'];
		} else {
			$data['name'] = '';
		}
		
		$this->load->model('localisation/language');

		$data['languages'] = $this->model_localisation_language->getLanguages();
		
		if (isset($this->request->post['author_title'])){
			$data['author_title'] = $this->request->post['author_title'];
		} elseif (!empty($module_info)) {
			$data['author_title'] = $module_info['author_title'];
		} else {
			$data['author_title'] = '';
		}
		
		if (isset($this->request->post['type'])) {
			$data['type'] = $this->request->post['type'];
		} elseif (!empty($module_info)) {
			$data['type'] = $module_info['type'];
		} else {
			$data['type'] = '';
		}
		
		if (isset($this->request->post['status'])) {
			$data['status'] = $this->request->post['status'];
		} elseif (!empty($module_info)) {
			$data['status'] = $module_info['status'];
		} else {
			$data['status'] = '';
		}
		
		if (isset($this->request->post['limit'])) {
			$data['limit'] = $this->request->post['limit'];
		} elseif (!empty($module_info)) {
			$data['limit'] = $module_info['limit'];
		} else {
			$data['limit'] = 5;
		}
		
		if (isset($this->request->post['width'])) {
			$data['width'] = $this->request->post['width'];
		} elseif (!empty($module_info)) {
			$data['width'] = $module_info['width'];
		} else {
			$data['width'] = 200;
		}
		
		if (isset($this->request->post['height'])) {
			$data['height'] = $this->request->post['height'];
		} elseif (!empty($module_info)) {
			$data['height'] = $module_info['height'];
		} else {
			$data['height'] = 200;
		}
		
		if (isset($this->request->post['display'])) {
			$data['display'] = $this->request->post['display'];
		} elseif (!empty($module_info)) {
			$data['display'] = $module_info['display'];
		} else {
			$data['display'] = '';
		}	
		
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/author', $data));
	}
	
	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/module/author')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}
		
		if ((utf8_strlen($this->request->post['name']) < 3) || (utf8_strlen($this->request->post['name']) > 64)) {
			$this->error['name'] = $this->language->get('error_name');
		}
		
		return !$this->error;
	}
	
	public function upgrade() {
		$this->load->language('extension/module/author');
		
		$this->document->setTitle($this->language->get('heading_title'));
		
		$query_book_detail = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "product` LIKE 'book_language'");

		//if exist, data will moved to new table 
		if($query_book_detail->num_rows){
			$query1 = $this->db->query("SELECT product_id, year, format, size, edition, page, language, date_published FROM " . DB_PREFIX . "product");
			
			if ($query1->num_rows) {
				foreach ($query1->rows as $row) {
					$query2 = $this->db->query("SELECT product_id FROM " . DB_PREFIX . "book_detail WHERE product_id = '" . (int)$row['product_id'] . "'");
			
					if (!$query2->num_rows) {
						$this->db->query("INSERT INTO " . DB_PREFIX . "book_detail SET product_id = '" . (int)$row['product_id'] . "', edition = '" . $this->db->escape($row['edition']) . "', page = '" . $this->db->escape($row['page']) . "', year = '" . $this->db->escape($row['year']) . "', format = '" . $this->db->escape($row['format']) . "', book_language = '" . $this->db->escape($row['book_language']) . "', book_size = '" . $this->db->escape($row['size']) . "', date_published = '" . $this->db->escape($row['date_published']) . "'");
					} else {
						$this->db->query("UPDATE " . DB_PREFIX . "book_detail SET edition = '" . $this->db->escape($row['edition']) . "', page = '" . $this->db->escape($row['page']) . "', year = '" . $this->db->escape($row['year']) . "', format = '" . $this->db->escape($row['format']) . "', book_language = '" . $this->db->escape($row['book_language']) . "', book_size = '" . $this->db->escape($row['size']) . "', date_published = '" . $this->db->escape($row['date_published']) . "' WHERE product_id = '" . (int)$row['product_id'] . "'");
					}
				}
			}
		
			$this->db->query("ALTER TABLE `" . DB_PREFIX . "product` DROP `edition`, DROP `page`, DROP `year`, DROP `format`, DROP `book_language`, DROP `size`, DROP `date_published`");
		}
		
		$this->session->data['upgrade_success'] = $this->language->get('text_upgrade_success');

		$this->response->redirect($this->url->link('extension/module/author', 'user_token=' . $this->session->data['user_token'], true));
	}
	
	public function install(){
        $sql = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "author` (
					`author_id` int(11) NOT NULL AUTO_INCREMENT,
					`image` varchar(255) DEFAULT NULL,
					`sort_order` int(3) NOT NULL DEFAULT '0',
					`status` tinyint(1) NOT NULL,
					`date_added` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
					`date_modified` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
					PRIMARY KEY (`author_id`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;";
		
		$this->db->query($sql);
		
		$sql = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "author_description` (
					`author_id` int(11) NOT NULL,
					`language_id` int(11) NOT NULL,
					`name` varchar(64) NOT NULL DEFAULT '',
					`description` text NOT NULL,
					`meta_title` varchar(255) NOT NULL,
					`meta_description` varchar(255) NOT NULL,
					`meta_keyword` varchar(255) NOT NULL,
					PRIMARY KEY (`author_id`,`language_id`),
					KEY `name` (`name`)
				) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;";

		$this->db->query($sql);
		
		$sql = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "author_to_layout` (
					`author_id` int(11) NOT NULL,
					`store_id` int(11) NOT NULL,
					`layout_id` int(11) NOT NULL,
					PRIMARY KEY (`author_id`,`store_id`)
				) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;";
				
		$this->db->query($sql);

		$sql = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "author_to_store` (
					`author_id` int(11) NOT NULL,
					`store_id` int(11) NOT NULL,
					PRIMARY KEY (`author_id`,`store_id`)
				) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;";
				
		$this->db->query($sql);
		
		$sql = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "product_to_author` (
					`product_id` int(11) NOT NULL,
					`author_id` int(11) NOT NULL,
					`author_attribute_id` int(11) NOT NULL DEFAULT '0',
					PRIMARY KEY (`product_id`,`author_id`)
				) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;";
		
		$this->db->query($sql);	
		
		$sql = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "author_attribute` (
					`author_attribute_id` int(11) NOT NULL AUTO_INCREMENT,
					`language_id` int(11) NOT NULL,
					`name` varchar(32) COLLATE utf8_bin NOT NULL,
					PRIMARY KEY (`author_attribute_id`,`language_id`)
				) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;";
		
		$this->db->query($sql);	
		
		$sql_detail = "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "book_detail` (
				`product_id` int(11) NOT NULL, 
				`year` VARCHAR(8) NOT NULL,
				`format` VARCHAR(24) NOT NULL,
				`book_size` VARCHAR(12) NOT NULL,
				`edition` VARCHAR(12) NOT NULL,
				`page` VARCHAR(12) NOT NULL,
				`book_language` VARCHAR(24) NOT NULL,
				`date_published` DATE NOT NULL DEFAULT '0000-00-00',
				PRIMARY KEY (`product_id`)
			) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;";
		
		$this->db->query($sql_detail);	
		
		$this->vqmod_script_dir = substr_replace(DIR_SYSTEM, '/vqmod/xml/', -8);
		$vqmod_name = 'author_module';
		
		if (is_file($this->vqmod_script_dir . $vqmod_name . '.xml_')) {
			rename($this->vqmod_script_dir . $vqmod_name . '.xml_', $this->vqmod_script_dir . $vqmod_name . '.xml');
		}
	}
	
	public function uninstall() {
		$this->vqmod_script_dir = substr_replace(DIR_SYSTEM, '/vqmod/xml/', -8);
		$vqmod_name = 'author_module';
		
		if (is_file($this->vqmod_script_dir . $vqmod_name . '.xml')) {
			rename($this->vqmod_script_dir . $vqmod_name . '.xml', $this->vqmod_script_dir . $vqmod_name . '.xml_');
		}
	}
}