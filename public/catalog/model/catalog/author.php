<?php
//==============================================
// Author Module
// Author 	: OpenCartBoost
// Email 	: support@opencartboost.com
// Website 	: http://www.opencartboost.com
//==============================================
class ModelCatalogAuthor extends Model {
    public function getAuthor($author_id) {
        $query = $this->db->query("SELECT DISTINCT * FROM " . DB_PREFIX . "author a LEFT JOIN " . DB_PREFIX . "author_description ad ON (a.author_id = ad.author_id) LEFT JOIN " . DB_PREFIX . "author_to_store a2s ON (a.author_id = a2s.author_id) WHERE a.author_id = '" . (int)$author_id . "' AND ad.language_id = '" . (int)$this->config->get('config_language_id') . "' AND a2s.store_id = '" . (int)$this->config->get('config_store_id') . "' AND a.status = '1'");

        return $query->row;
    }

    public function getAuthors($data = array()){
		if ($data) {
			$sql = "SELECT * FROM " . DB_PREFIX . "author a LEFT JOIN " . DB_PREFIX . "author_description ad ON (a.author_id = ad.author_id) LEFT JOIN " . DB_PREFIX . "author_to_store a2s ON (a.author_id = a2s.author_id) WHERE ad.language_id = '" . (int)$this->config->get('config_language_id') . "' AND a2s.store_id = '" . (int)$this->config->get('config_store_id') . "'  AND a.status = '1'";

			$sql .= " ORDER BY a.sort_order, LCASE(ad.name)";
			
			if (isset($data['order']) && ($data['order'] == 'DESC')) {
				$sql .= " DESC";
			} else {
				$sql .= " ASC";
			}
			
			if (isset($data['start']) || isset($data['limit'])) {
				if ($data['start'] < 0) {
					$data['start'] = 0;
				}					

				if ($data['limit'] < 1) {
					$data['limit'] = 20;
				}	
			
				$sql .= " LIMIT " . (int)$data['start'] . "," . (int)$data['limit'];
			}
			
	  		$query = $this->db->query($sql);
		
			return $query->rows;		
		} else {
			$author_data = $this->cache->get('author_data');

			if (!$author_data) {
				$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "author a LEFT JOIN " . DB_PREFIX . "author_description ad ON (a.author_id = ad.author_id) LEFT JOIN " . DB_PREFIX . "author_to_store a2s ON (a.author_id = a2s.author_id) WHERE ad.language_id = '" . (int)$this->config->get('config_language_id') . "' AND a2s.store_id = '" . (int)$this->config->get('config_store_id') . "'  AND a.status = '1' ORDER BY a.sort_order, LCASE(ad.name)");
				
				$author_data = $query->rows;
			
				$this->cache->set('author', $author_data);
			}
			
			return $author_data;
		}
	}

    public function getAuthorLayoutId($author_id) {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "author_to_layout WHERE author_id = '" . (int)$author_id . "' AND store_id = '" . (int)$this->config->get('config_store_id') . "'");

        if ($query->num_rows) {
            return $query->row['layout_id'];
        } else {
            return 0;
        }
    }
}
?>