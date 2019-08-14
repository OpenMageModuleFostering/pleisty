<?php

/**
 * @author Pleisty <tech@pleisty.com>
 */

class F5_Pleisty_IndexController extends Mage_Core_Controller_Front_Action
{

    private $cat_tree_clean = [];
    private $pack_mode = "json";

    private function get_param($param_name, $default_value = null) {
        $params = $this->getRequest()->getParams();
        if (isset($params[$param_name])) return $params[$param_name];
        return $default_value;
    }
    
    private function dump($type,$value) {
        if ($this->pack_mode == "json") {
            echo json_encode([$type,$value]) . "\n";return;
            echo json_encode([$type,$value], JSON_PRETTY_PRINT) . "\n";
        }
        if ($this->pack_mode == "serialize") {
            echo serialize([$type,$value]) . "\n";
        }
    }
    
    private function get_extension_config($only_key = null) {
        if ($only_key != null) return Mage::getStoreConfig('tab1/general/' . $only_key);
        $keys = array(
            "site_hash_varible",    
            "base_tracking",    
            "customer_tracking",    
            "product",    
            "product_listing",    
            "shopping_cart",    
            "wishlist_tracking",    
            "checkout_process_tracking",    
            "checkout_finalized",    
            "other_content",    
        );        
        $values = array();
        foreach ($keys as $key) {
            $values[$key] = Mage::getStoreConfig('tab1/general/' . $key);
        }
        return $values;
    }
    
    private function check_req_key() {
        if (!$this->get_param("rq_key")) {
            $this->dump("auth_err", "empty rq_key");
            return;
        }
        //return true;
        $url_base = "http://magento1x-api.pleisty.com/api/magento1x/feed_auth";
        $url = $url_base
            . "?rq_key=" . urlencode($this->get_param("rq_key"))
            . "&rq_sig=" . urlencode($this->get_param("rq_sig"))
            . "&rq_ts=" . urlencode($this->get_param("rq_ts"))
            . "&site_hash=" . urlencode($this->get_extension_config('site_hash_varible'))
        ;
        $this->dump("check_request_at", $url);
        $response = trim(file_get_contents($url));
        $this->dump("check_request_rsp", $response);
        return $response == $this->get_param("rq_key");
    }
    
    public function indexAction() {
        $ts = microtime(1);        
        header('Content-Type: text/plain');
        $this->pack_mode = $this->get_param("pack_mode","json");        
        if (!$this->check_req_key()) {
            $this->dump("auth_err", "Failed to check request");
            return;
        }
        
        $this->dump("req_params", $this->getRequest()->getParams());

        $this->dump("magento_version", Mage::getVersion());
        $this->dump("php_version", phpversion());
        $this->dump("default_curr", Mage::app()->getStore()->getCurrentCurrencyCode());
        $this->dump("tz", Mage::getStoreConfig('general/locale/timezone'));
        $this->dump("ext_name", $this->get_extension_name());
        $this->dump("ext_ver", $this->get_extension_version());
        $this->dump("ext_config", $this->get_extension_config());
        $this->dump("ts_start", $ts);
        $this->dump("mageto_base_url", Mage::getBaseUrl());
        $this->dump("mageto_current_store_id", Mage::app()->getStore()->getStoreId());
        $this->dump("mageto_current_store", Mage::app()->getStore()->getData());
        $this->dump("mageto_current_store_url", Mage::app()->getStore()->getHomeUrl());
        $magento_stores = Mage::app()->getStores();
        foreach ($magento_stores as $magento_store) {
            $magento_store_arr = $magento_store->getData();
            $magento_store_arr['url'] = $magento_store->getHomeUrl();
            $this->dump("mageto_store[]", $magento_store_arr);
        }
        

        $baseCurrencyCode = Mage::app()->getBaseCurrencyCode();        
        $allowedCurrencies = Mage::getModel('directory/currency')->getConfigAllowCurrencies();    
        $currencyRates = Mage::getModel('directory/currency')->getCurrencyRates($baseCurrencyCode, array_values($allowedCurrencies));
        $this->dump("mageto_base_currency", $baseCurrencyCode);
        $this->dump("mageto_allowed_currencies", $allowedCurrencies);
        $this->dump("mageto_currency_rates", $currencyRates);
        
        
        $actions = $this->get_param("actions",[]);
        $this->dump("actions", $actions);
        if (in_array("products", $actions)) $this->export_product_catalog();        
        if (in_array("users", $actions)) $this->export_user_catalog();        
        if (in_array("orders", $actions)) $this->export_order_catalog();        
        if (in_array("carts", $actions)) $this->export_cart_catalog();        
        if (in_array("subscribers", $actions)) $this->export_subscriber_catalog();
    }
    
    private function get_extension_name() {
        return str_replace("_IndexController", "", get_class($this));
    }
    private function get_extension_version() {
        $name = $this->get_extension_name();
        try {
           return Mage::getConfig()->getNode()->modules->$name;
           return (string)Mage::getConfig()->getNode()->modules->$name->version;
        } catch (Exception $e) {
           return $e->getMessage(); 
        }
    }
    
    private function get_products_collection($first = false) {
        
        $products = Mage::getModel('catalog/product')->getCollection();
                        
        if ($first) $this->dump("order_by",$this->get_param("order_by","updated_at"));
        if ($first) $this->dump("order_dir",$this->get_param("order_dir","desc"));        
        $products->setOrder($this->get_param("order_by","updated_at"), $this->get_param("order_dir","desc"));
        
        $attribute_map_list = json_decode($this->get_param("attribute_map"), true) ?: array("*");
        if ($first) $this->dump("attribute_map_list",$attribute_map_list);
        if ($first) $this->dump("product_attributes_ignore",$this->get_param("product_attributes_ignore",['category_ids', 'group_price']));
        foreach ($attribute_map_list as $attribute_map)
            $products->addAttributeToSelect($attribute_map);
        
        
        $filters = json_decode($this->get_param("filters"), true) ?: array();
        if ($first) $this->dump("filters",$filters);
        foreach ($filters as $filter) {
            call_user_func_array(array($products,'addFieldToFilter'), $filter);
        }
        
        
        if ($first) $this->dump("page_size",$this->get_param("page_size",10));
        $products->setPageSize($this->get_param("page_size",10));
        
        return $products;
    }
    
    private function export_product_catalog() {
        
        
        $ts = microtime(1);       
        
        
        $cat_tree = $this->get_categories_tree();        
        $this->cat_tree_clean = [];
        foreach ([
            "name",
            "url_path",
        ] as $p)
            foreach ($cat_tree as $cat_id => $cat_path)
                $this->cat_tree_clean[$p][$cat_id] = array_map(
                    function($e) use ($p) { return isset($e[$p]) ? $e[$p] : ""; },
                    $cat_path);
        $this->dump("cat_tree",$this->cat_tree_clean);
                
        
        $products = $this->get_products_collection(true);
        
        $this->dump("page_start",$this->get_param("page_start",1));
        $currentPage = $this->get_param("page_start",1);
        
        $pages = $products->getLastPageNumber();
        $last_page = $pages;
        $this->dump("page_last",$pages);
        $this->dump("page_max",$this->get_param("page_max",$pages));
        $pages = $this->get_param("page_max",$pages);
        $this->dump("products_count",$products->getSize());
        
        $k = 0;        
        do {
            $this->dump("at_page",[$currentPage,$pages,$last_page]);
            $products->setCurPage($currentPage);
            foreach ($products as $product) {
                $this->dump("product_k",$k);
                try {
                    $this->dump("product[]",$this->map_product($product));
                } catch (Exception $e) {
                    $this->dump("product_err[]", [$product->getId(), $e->getMessage(), $e->getTraceAsString()]);                    
                }
                $k++;
            }
            $currentPage++;
            $products->clear();
            //$products = $this->get_products_collection();            
        } while ($currentPage <= $pages);
        
        $this->dump("products_done",$k);
        $this->dump("products_took",(microtime(1) - $ts));
        $this->dump("products_mem",memory_get_usage());
        $this->dump("products_mmem",memory_get_peak_usage());
    }

    private function map_product($product) {
        $product = $product;
        
        if ($this->get_param("send_core")) {
            $p['core'] = $product->getData();
        }
        
        $p['item_id'] = $product->getId();
        $p['item_created_at'] = Mage::getModel("core/date")->timestamp($product->getCreatedAt());
        $p['item_updated_at'] = Mage::getModel("core/date")->timestamp($product->getUpdatedAt());
        $p['item_sku'] = $product->getSku();
        $p['store_id'] = $product->getStoreId();
        $p['store_ids'] = $product->getStoreIds();
        $p['item_oldprice'] = (float)$product->getPrice();        
        $p['item_price'] = (float)$product->getFinalPrice();
        if ((float)$p['item_price']) {
            $p['item_discount_procent'] = (float)number_format(100*($p['item_oldprice'] - $p['item_price']) / $p['item_price'],2,".","");
            $p['item_discount_value'] = (float)($p['item_oldprice'] - $p['item_price']);
        }
        //$p['_item_price_curr_html'] = Mage::helper('core')->currency($product->getPrice());
        $p['item_curr'] = Mage::app()->getStore($product->getStoreId())->getCurrentCurrencyCode();
        $p['item_curr_symbol'] = Mage::app()->getLocale()->currency($p['item_curr'])->getSymbol();
        $p['item_curr_name'] = Mage::app()->getLocale()->currency($p['item_curr'])->getName();
        //$p['_item_curr'] = Mage::helper('core')->currency($product->getFinalPrice(),true,false);
        $p['item_url'] = $product->getProductUrl();        
        $p['item_stock'] = $product->stock_item->getIsInStock();
        $p['item_title'] = $product->getName();
        $p['item_description'] = $product->getDescription();
        $p['item_short_description'] = $product->getShortDescription();
        unset($p['stock_item']);
        //$p['_stock_qty'] = $product->stock_item->getQty();
        
        try {
            $p['item_img_thumbnail'] = (string)Mage::helper('catalog/image')->init($product, 'thumbnail');
            $p['item_img'] = (string)Mage::helper('catalog/image')->init($product, 'small_image');
            $p['item_img_big'] = (string)Mage::helper('catalog/image')->init($product, 'image'); //->resize(135,135);
            $gallery_images = Mage::getModel('catalog/product')->load($product->getId())->getMediaGalleryImages();
            foreach($gallery_images as $g_image) {
                $p['item_img_all'][] = $g_image['url'];
            }
        } catch (Exception $e) {
            $p['_item_img_err'] = [$e->getMessage(), $e->getTraceAsString()];
        }
        
        
        
        
        $p['cat_list_id'] = $product->getCategoryIds();
        foreach ($this->cat_tree_clean as $cat_type => $cat_type_paths)
            foreach ($p['cat_list_id'] as $cat_id) {
                $p['cat_list_' . $cat_type][] = $cat_type_paths[$cat_id];      
            }
            
                
        $attributes_ignore = $this->get_param("product_attributes_ignore",['category_ids', 'group_price']);
        if (!in_array("*", $attributes_ignore)) {
            $attrs = $product->getAttributes();        
            foreach ($attrs as $a => $ao) {
                try {
                    if (in_array($a,$attributes_ignore)) continue;
                                     
                    if ($product->getData($a) !== null){                    
                        $p["attr"][$a] = $product->getAttributeText($a);
                    } else {
                        $p['_attrs_empty'][] = $a;
                    }                    
                } catch (Exception $e) {
                    $p['_attrs_err'][$a] = [$e->getMessage(), $e->getTraceAsString()];
                }
            }
        }
        
        return $p;        
    }
    
    private function get_categories_tree($path = null){
        $return = [];
              
        if (!count($path)) {
            $parentId = Mage::app()->getStore()->getRootCategoryId();
            $parent = Mage::getModel('catalog/category')->load($parentId)->getData();            
            $path = [$parent];
            $return[$parentId] = $path;
        }
        $last = end($path);
        
        $sub_cats = Mage::getModel('catalog/category')->getCollection()
                    //->addAttributeToSelect('*')
                    ->addAttributeToSelect('entity_id')
                    ->addAttributeToSelect('parent_id')
                    ->addAttributeToSelect('path')
                    ->addAttributeToSelect('position')
                    ->addAttributeToSelect('level')
                    ->addAttributeToSelect('name')
                    ->addAttributeToSelect('url_path')
                    ->addAttributeToFilter('is_active','1')                    
                    //->addAttributeToFilter('include_in_menu','1')
                    ->addAttributeToFilter('parent_id',array('eq' => $last['entity_id']))
        ;

        
        foreach ($sub_cats as $c=>$co) {
            $cd = $co->getData();
            $this_path = array_merge($path,[$cd]); 
            $return[$cd['entity_id']] = $this_path;
            $return = $return + self::get_categories_tree($this_path);                     
        }
        return $return;    
    }


    private function get_users_collection($first = false) {
        $users = Mage::getModel('customer/customer')->getCollection();
                        
        if ($first) $this->dump("order_by",$this->get_param("order_by","updated_at"));
        if ($first) $this->dump("order_dir",$this->get_param("order_dir","desc"));        
        $users->setOrder($this->get_param("order_by","updated_at"), $this->get_param("order_dir","desc"));
        
        $attribute_map_list = json_decode($this->get_param("attribute_map"), true) ?: array("*");
        if ($first) $this->dump("attribute_map_list",$attribute_map_list);        
        foreach ($attribute_map_list as $attribute_map)
            $users->addAttributeToSelect($attribute_map);
        
        
        $filters = json_decode($this->get_param("filters"), true) ?: array();
        if ($first) $this->dump("filters",$filters);
        foreach ($filters as $filter) {
            call_user_func_array(array($users,'addFieldToFilter'), $filter);
        }
        
        
        if ($first) $this->dump("page_size",$this->get_param("page_size",10));
        $users->setPageSize($this->get_param("page_size",10));
        
        return $users;
    }

    private function export_user_catalog() {
        $ts = microtime(1);       
        $users = $this->get_users_collection(true);              
                
        $this->dump("page_start",$this->get_param("page_start",1));
        $currentPage = $this->get_param("page_start",1);        
        $pages = $users->getLastPageNumber();
        $last_page = $pages;
        $this->dump("page_last",$pages);
        $this->dump("page_max",$this->get_param("page_max",$pages));
        $pages = $this->get_param("page_max",$pages);
        $this->dump("users_count",$users->getSize());
        
        $k = 0;        
        do {
            $this->dump("at_page",[$currentPage,$pages,$last_page]);
            $users->setCurPage($currentPage);
                        
            foreach ($users as $user) {
                $this->dump("user_k",$k);
                try {
                    $this->dump("user[]",$this->map_user($user));
                } catch (Exception $e) {
                    $this->dump("user_err[]", [$user->getId(), $e->getMessage(), $e->getTraceAsString()]);                    
                }
                
                $k++;                
            }    
            
            $currentPage++;
            $users->clear();
        } while ($currentPage <= $pages);
        
        $this->dump("users_done",$k);
        $this->dump("users_took",(microtime(1) - $ts));
        $this->dump("users_mem",memory_get_usage());
        $this->dump("users_mmem",memory_get_peak_usage());
        
        
        return;
    }
    
    private function map_user($user) {
        $u = [];
        
        $u['customer_id'] = $user->getData('email');
        $u['email'] = $user->getData('email');
        $u['is_active'] = $user->getData('is_active');
        $u['first_name'] = $user->getData('firstname');
        $u['last_name'] = $user->getData('lastname');
        $u['magento_customer_id'] = $user->getData('entity_id');
        $u['created_at'] = Mage::getModel("core/date")->timestamp($user->getCreatedAt());
        $u['updated_at'] = Mage::getModel("core/date")->timestamp($user->getUpdatedAt());
        
        if ($this->get_param("send_core")) {
            $u['core'] = $user->getData();
            unset($u['core']['password_hash']);
        }
        
        return $u;
    }
    
    private function get_orders_collection($first = false) {
        $users = Mage::getModel('sales/order')->getCollection();
                        
        if ($first) $this->dump("order_by",$this->get_param("order_by","updated_at"));
        if ($first) $this->dump("order_dir",$this->get_param("order_dir","desc"));        
        $users->setOrder($this->get_param("order_by","updated_at"), $this->get_param("order_dir","desc"));
        
        $attribute_map_list = json_decode($this->get_param("attribute_map"), true) ?: array("*");
        if ($first) $this->dump("attribute_map_list",$attribute_map_list);
        if ($first) $this->dump("order_attributes_ignore",$this->get_param("order_attributes_ignore",[]));
        foreach ($attribute_map_list as $attribute_map)
            $users->addAttributeToSelect($attribute_map);
        
        
        $filters = json_decode($this->get_param("filters"), true) ?: array();
        if ($first) $this->dump("filters",$filters);
        foreach ($filters as $filter) {
            call_user_func_array(array($users,'addFieldToFilter'), $filter);
        }
        
        
        if ($first) $this->dump("page_size",$this->get_param("page_size",10));
        $users->setPageSize($this->get_param("page_size",10));
        
        return $users;
    }

    private function export_order_catalog() {
        $ts = microtime(1);       
        $collection = $this->get_orders_collection(true);              
                
        $this->dump("page_start",$this->get_param("page_start",1));
        $currentPage = $this->get_param("page_start",1);        
        $pages = $collection->getLastPageNumber();
        $last_page = $pages;
        $this->dump("page_last",$pages);
        $this->dump("page_max",$this->get_param("page_max",$pages));
        $pages = $this->get_param("page_max",$pages);
        $this->dump("orders_count",$collection->getSize());
        
        $k = 0;        
        do {
            $this->dump("at_page",[$currentPage,$pages,$last_page]);
            $collection->setCurPage($currentPage);
                        
            foreach ($collection as $entiy) {
                $this->dump("order_k",$k);
                try {
                    $this->dump("order[]",$this->map_order($entiy));
                } catch (Exception $e) {
                    $this->dump("order_err[]", [$entiy->getId(), $e->getMessage(), $e->getTraceAsString()]);                    
                }
                
                $k++;                
            }    
            
            $currentPage++;
            $collection->clear();
           
            //$collection = $this->get_products_collection();            
        } while ($currentPage <= $pages);
        
        $this->dump("orders_done",$k);
        $this->dump("orders_took",(microtime(1) - $ts));
        $this->dump("orders_mem",memory_get_usage());
        $this->dump("orders_mmem",memory_get_peak_usage());
        
        
        return;
    }
    
    private function map_order($entity) {
        $e = [];
        $e['transaction_id'] = $entity->getId();
        $e['curr'] = $entity->getData("order_currency_code");
        $e['total'] = (float)$entity->getData("subtotal");
        $e['grand_total'] = (float)$entity->getData("grand_total");
        $e['email'] = $entity->getData("customer_email");
        $e['customer_id'] = $entity->getData("customer_email");
        $e['magento_customer_id'] = $entity->getData("customer_id");
        $e['customer']['customer_email'] = $entity->getData("customer_email");
        $e['customer']['first_name'] = $entity->getData("customer_firstname");
        $e['customer']['last_name'] = $entity->getData("customer_lastname");
        $e['customer']['middle_name'] = $entity->getData("customer_middlename");
        $e['customer']['is_guest'] = $entity->getData("customer_is_guest");
                          
        
        $cart_products = $entity->getAllItems();
        foreach ($cart_products as $cart_product) {
            $product = Mage::getModel('catalog/product')->load($cart_product->getProductId());
            $product_entry = [];
            $product_entry['url'] = $product->getProductUrl();
            $product_entry['item_id'] = $cart_product->getProductId();
            $product_entry['qty'] = (float)$cart_product->getData('qty_ordered');
            $product_entry['price'] = (float)$cart_product->getPrice();
            if ($this->get_param("send_core")) $product_entry['core'] = $cart_product->getData();
            $e['items'][] = $product_entry;
        }
        if ($this->get_param("send_core")) $e['core'] = $entity->getData();
        return $e;
    }
    
    
    private function get_carts_collection($first = false) {
        $collection = Mage::getModel('sales/quote')->getCollection();
                        
        if ($first) $this->dump("order_by",$this->get_param("order_by","updated_at"));
        if ($first) $this->dump("order_dir",$this->get_param("order_dir","desc"));        
        $collection->setOrder($this->get_param("order_by","updated_at"), $this->get_param("order_dir","desc"));
        
                
        $filters = json_decode($this->get_param("filters"), true) ?: array();
        if ($first) $this->dump("filters",$filters);
        foreach ($filters as $filter) {
            call_user_func_array(array($collection,'addFieldToFilter'), $filter);
        }
        
        
        if ($first) $this->dump("page_size",$this->get_param("page_size",10));
        $collection->setPageSize($this->get_param("page_size",10));
        
        return $collection;
    }

    private function export_cart_catalog() {
        $ts = microtime(1);       
        $collection = $this->get_carts_collection(true);              
                
        $this->dump("page_start",$this->get_param("page_start",1));
        $currentPage = $this->get_param("page_start",1);        
        $pages = $collection->getLastPageNumber();
        $last_page = $pages;
        $this->dump("page_last",$pages);
        $this->dump("page_max",$this->get_param("page_max",$pages));
        $pages = $this->get_param("page_max",$pages);
        $this->dump("cart_count",$collection->getSize());
        
        $k = 0;        
        do {
            $this->dump("at_page",[$currentPage,$pages,$last_page]);
            $collection->setCurPage($currentPage);
                        
            foreach ($collection as $entiy) {
                $this->dump("cart_k",$k);
                try {
                    $this->dump("cart[]",$this->map_cart($entiy));
                } catch (Exception $e) {
                    $this->dump("cart_err[]", [$entiy->getId(), $e->getMessage(), $e->getTraceAsString()]);                    
                }
                
                $k++;                
            }
            
            $currentPage++;
            $collection->clear();
           
            //$collection = $this->get_products_collection();            
        } while ($currentPage <= $pages);
        
        $this->dump("orders_done",$k);
        $this->dump("orders_took",(microtime(1) - $ts));
        $this->dump("orders_mem",memory_get_usage());
        $this->dump("orders_mmem",memory_get_peak_usage());
        
        
        return;
    }
    
    private function map_cart($entity) {
        $e = [];
        $e['cart_id'] = $entity->getId();
        $e['curr'] = $entity->getData("quote_currency_code");
        $e['total'] = (float)$entity->getData("subtotal");
        $e['grand_total'] = (float)$entity->getData("grand_total");
        $e['email'] = $entity->getData("customer_email");
        $e['customer_id'] = $entity->getData("customer_email");
        $e['magento_customer_id'] = $entity->getData("customer_id");
        
        foreach ($entity->getAllItems() as $cart_product) {
            $product = Mage::getModel('catalog/product')->load($cart_product->getProductId());
            $product_entry = [];
            $product_entry['url'] = $product->getProductUrl();
            $product_entry['item_id'] = $cart_product->getProductId();            
            $product_entry['qty'] = (float)$cart_product->getQty();
            $product_entry['price'] = (float)$cart_product->getPrice();            
            if ($this->get_param("send_core")) $product_entry['core'] = $cart_product->getData();
            $e['items'][] = $product_entry;            
        }
        if ($this->get_param("send_core"))  $e['core'] = $entity->getData();
        return $e;
    }
    
    
    private function get_subscribers_collection($first = false) {
        $users = Mage::getModel('newsletter/subscriber')->getCollection();
                
                        
        if ($first) $this->dump("order_by",$this->get_param("order_by","subscriber_id"));
        if ($first) $this->dump("order_dir",$this->get_param("order_dir","desc"));        
        $users->setOrder($this->get_param("order_by","subscriber_id"), $this->get_param("order_dir","desc"));
        
        //$attribute_map_list = json_decode($this->get_param("attribute_map"), true) ?: array("*");
        //if ($first) $this->dump("attribute_map_list",$attribute_map_list);
        //if ($first) $this->dump("subscriber_attributes_ignore",$this->get_param("subscriber_attributes_ignore",[]));
        //foreach ($attribute_map_list as $attribute_map)
        //    $users->addAttributeToSelect($attribute_map);
        
        
        $filters = json_decode($this->get_param("filters"), true) ?: array();
        if ($first) $this->dump("filters",$filters);
        foreach ($filters as $filter) {
            call_user_func_array(array($users,'addFieldToFilter'), $filter);
        }
        
        
        if ($first) $this->dump("page_size",$this->get_param("page_size",10));
        $users->setPageSize($this->get_param("page_size",10));
        
        return $users;
    }

    private function export_subscriber_catalog() {
        $ts = microtime(1);       
        $collection = $this->get_subscribers_collection(true);              
                
        $this->dump("page_start",$this->get_param("page_start",1));
        $currentPage = $this->get_param("page_start",1);        
        $pages = $collection->getLastPageNumber();
        $last_page = $pages;
        $this->dump("page_last",$pages);
        $this->dump("page_max",$this->get_param("page_max",$pages));
        $pages = $this->get_param("page_max",$pages);
        $this->dump("subscribers_count",$collection->getSize());
        
        $k = 0;        
        do {
            $this->dump("at_page",[$currentPage,$pages,$last_page]);
            $collection->setCurPage($currentPage);
                        
            foreach ($collection as $entiy) {
                $this->dump("subscriber_k",$k);
                try {
                    $this->dump("subscriber[]",$this->map_subscriber($entiy));
                } catch (Exception $e) {
                    $this->dump("subscriber_err[]", [$entiy->getId(), $e->getMessage(), $e->getTraceAsString()]);                    
                }
                
                $k++;                
            }    
            
            $currentPage++;
            $collection->clear();
           
            //$collection = $this->get_products_collection();            
        } while ($currentPage <= $pages);
        
        $this->dump("subscribers_done",$k);
        $this->dump("subscribers_took",(microtime(1) - $ts));
        $this->dump("subscribers_mem",memory_get_usage());
        $this->dump("subscribers_mmem",memory_get_peak_usage());
        
        
        return;
    }
    
    private function map_subscriber($entity) {
        $e = [];        
        //$e['_core'] = $entity->getData();
        $e['customer_id'] = $entity->getData("subscriber_email");
        $e['email'] = $entity->getData("subscriber_email");
        $e['subscriber_status'] = $entity->getData("subscriber_status");
        $e['magento_customer_id'] = $entity->getData("customer_id");
        
        if ($this->get_param("send_core")) $e['core'] = $entity->getData();
        return $e;
    }
}
