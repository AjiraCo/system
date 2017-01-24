<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Generator PDF with webkit pdf generator
 *
 * @package  Billing
 * @since    3.0
 */
class Generator_WkPdf extends Billrun_Generator_Pdf {

	protected static $type = 'invoice_export';
	
	protected $accountsToInvoice = FALSE;
	protected $filePermissions =  0666; 
	protected $invoice_threshold= 0.005;
	
	/**
	 *
	 * @var Mongodloid_Cursor
	 */
	protected $billrun_data;
	
	public function __construct($options) {
		parent::__construct($options);
		
		$this->filePermissions = Billrun_Util::getFieldVal( $options['file_permisison'], 0666 );		
		
		//handle accounts both as  an array and as a comma seperated list (CSV row)
		$this->accountsToInvoice = Billrun_Util::getFieldVal( $options['accounts'], FALSE, function($acts) {return is_array($acts) ? $acts : explode(',',$acts); });
		
		$this->header_path = APPLICATION_PATH . Billrun_Util::getFieldVal( $options['header_tpl'], "/application/views/invoices/header/header_tpl.html" );
		//TODO: use tenant LOGO
		$this->logo_path = APPLICATION_PATH . Billrun_Util::getFieldVal( $options['header_tpl'], "/application/views/invoices/theme/logo.png" );
		$this->billrun_footer_logo_path = APPLICATION_PATH . "/application/views/invoices/theme/logo.png";
		$this->footer_path = APPLICATION_PATH . Billrun_Util::getFieldVal( $options['footer_tpl'], "/application/views/invoices/footer/footer_tpl.html" );
		$this->wkpdf_exec = Billrun_Util::getFieldVal( $options['exec'],Billrun_Factory::config()->getConfigValue('wkpdf.exec', 'wkhtmltopdf') );
		$this->view_path = Billrun_Factory::config()->getConfigValue('application.directory') . '/views/' .'invoices/';
		
		$this->paths = array(
			'html' => $this->export_directory.DIRECTORY_SEPARATOR.'html/'.$this->stamp.'/',
			'pdf' => $this->export_directory.DIRECTORY_SEPARATOR.'pdf/'.$this->stamp.'/',
			'tmp' => sys_get_temp_dir() . '/' . str_replace(' ', '_', $this->getCompanyName()) . '/' . $this->stamp . '/',
		);
		
		$this->tmp_paths = array(
			'header' => $this->paths['tmp'] . 'tmp_header.html',
			'footer' => $this->paths['tmp'] . 'tmp_footer.html',
		);
		
		//only generate bills that are 0.01 and above.
		$this->invoice_threshold = Billrun_Util::getFieldVal($options['generator']['minimum_amount'], 0.005);
		$this->css_path = APPLICATION_PATH . Billrun_Factory::config()->getConfigValue(self::$type . '.theme');
		$this->font_awesome_css_path = APPLICATION_PATH . '/public/css/font-awesome.css';

	}
	
	/**
	 * Prepre the invoice view for invoice  generation.
	 * @param type $params 
	 */
	public function prepereView($params = FALSE) {
		$this->view = new Billrun_View_Invoice($this->view_path);
		$this->view->assign('css_path',  $this->css_path);
		$this->view->assign('decimal_mark',  Billrun_Factory::config()->getConfigValue(self::$type . '.decimal_mark', '.'));
		$this->view->assign('thousands_separator',  Billrun_Factory::config()->getConfigValue(self::$type . '.thousands_separator', ','));
		$this->view->assign('company_name', Billrun_Util::getCompanyName());
		$this->view->assign('sumup_template',  APPLICATION_PATH . Billrun_Factory::config()->getConfigValue(self::$type . '.sumup_template', ''));
		$this->view->assign('details_template',  APPLICATION_PATH . Billrun_Factory::config()->getConfigValue(self::$type . '.details_template', ''));
		$this->view->assign('currency',  Billrun_Factory::config()->getConfigValue('pricing.currency', ''));
		$this->view->assign('date_format',  Billrun_Factory::config()->getConfigValue(self::$type . '.date_format', 'd/m/Y H:i:s'));
		$this->view->assign('font_awesome_css_path', $this->font_awesome_css_path);
	}
	
	/*
	 * generate invoice for each billrun object fetched in load()
	 * an html file for each invoice is created
	 * for each html a pdf invoice file is generated using wkhtmltopdf tool
	 */
	public function generate() {
		
		$this->prepereView();
		
		foreach ($this->billrun_data as $object) {
			$this->generateAccountInvoices($object);
		}
	}
	
	/*
	* load billrun objects from billrun collection  
	*/
	public function load() {
		$billrun = Billrun_Factory::db()->billrunCollection();
		$query = array('billrun_key' => $this->stamp, '$or' => array(	
																		array('totals.after_vat'=>array('$not' => array('$gt'=>-$this->invoice_threshold,'$lt'=>$this->invoice_threshold)) ) , 
//																		array('totals.before_discounts'=>array('$not' => array('$gt'=>-$this->invoice_threshold,'$lt'=>$this->invoice_threshold))) 
																	) );
		if(!empty($this->accountsToInvoice)) {
			$query['aid'] = array('$in'=> $this->accountsToInvoice);
		}
		$this->billrun_data = $billrun->query($query)->cursor()->limit($this->limit)->skip($this->limit * $this->page);
	}
	
	/**
	 * Generate account invoice.
	 * @param type $account the account to generate an invoice for.
	 */
	public  function generateAccountInvoices($account, $lines = FALSE) {		

			$this->addFolder($this->paths['html']);
			$this->addFolder($this->paths['pdf']);
			$this->addFolder($this->paths['tmp']);
			$this->view->assign('data',$account);
			$this->view->assign('details_keys',$this->getDetailsKeys());
			if(empty($lines)) {
				$this->view->add_lines();
			}
			
			$file_name = $account['billrun_key']."_".$account['aid']."_".$account['invoice_id'].".html";
			$pdf_name = $account['billrun_key']."_".$account['aid']."_".$account['invoice_id'].".pdf";
			$html = $this->paths['html'].$file_name;
			$pdf = $this->paths['pdf'].$pdf_name;
					
			file_put_contents($html, $this->view->render($this->view_path . 'invoice.phtml'));
			chmod( $html, $this->filePermissions );
			
			$this->updateHtmlDynamicData($account);
			
			Billrun_Factory::log('Generating invoice '.$account['billrun_key']."_".$account['aid']."_".$account['invoice_id'],Zend_Log::INFO);
			exec($this->wkpdf_exec . " -R 0.1 -L 0 -B 14 --print-media-type --header-html {$this->tmp_paths['header']} --footer-html {$this->tmp_paths['footer']} {$html} {$pdf}");
			chmod( $pdf,$this->filePermissions );
	}
	
	protected function getDetailsKeys() {
		return Billrun_Factory::config()->getConfigValue('billrun.breakdowns', array());
	}
	
	protected function getTranslations() {
		return Billrun_Factory::config()->getConfigValue(self::$type . '.html_translation', array());
	}

	protected function updateHtmlDynamicData($account) {
		$translations = $this->getTranslations();
		
		$headerContent = file_get_contents($this->header_path);
		$headerContent = str_replace("[[invoiceHeaderTemplate]]", $this->getInvoiceHeaderContent(), $headerContent);		
		$headerContent = str_replace("[[invoiceTemplateStyle]]", $this->css_path, $headerContent);		
		
		$footerContent = file_get_contents($this->footer_path);
		$footerContent = str_replace("[[invoiceFooterTemplate]]", $this->getInvoiceFooterContent(), $footerContent);
		$footerContent = str_replace("[[invoiceTemplateStyle]]", $this->css_path, $footerContent);
		$footerContent = str_replace("[[invoiceTemplateFontAwesomeStyle]]", $this->font_awesome_css_path, $footerContent);

		foreach ($translations as $translation) {
			switch ($translation) {
				case "company_name":
					$replace = $this->getCompanyName();
					$headerContent = str_replace("[[$translation]]", $replace, $headerContent);
					$footerContent = str_replace("[[$translation]]", $replace, $footerContent);
				case "date":
					$replace = $this->getHeaderDate();
					$headerContent = str_replace("[[$translation]]", $replace, $headerContent);
					$footerContent = str_replace("[[$translation]]", $replace, $footerContent);
			}
		}
		file_put_contents($this->tmp_paths['header'], $headerContent);
		file_put_contents($this->tmp_paths['footer'], $footerContent);
	}
	
	protected function getCompanyName() {
		return Billrun_Util::getCompanyName();
	}
	
	protected function getCompanyAddress() {
		return Billrun_Util::getCompanyAddress();
	}
	protected function getCompanyWebsite() {
		return Billrun_Util::getCompanyWebsite();
	}
	
	protected function getCompanyPhone() {
		return Billrun_Util::getCompanyPhone();
	}
	
	protected function getCompanyEmail() {
		return Billrun_Util::getCompanyEmail();
	}
	
	protected function getHeaderDate() {
		$date_seperator = Billrun_Factory::config()->getConfigValue(self::$type . '.date_seperator', '/');
		return date('d' . $date_seperator . 'm' . $date_seperator . 'Y');
	}
	
	protected function getInvoiceHeaderContent() {
		//TODO : in future header should came from config
		return "
			<div class='table'>
				<table>
					<tbody>
					<tr>
						<td><img src='" . $this->logo_path . "' alt='' style='width:100px;object-fit:contain;'>&nbsp;&nbsp;" . $this->getCompanyName() . "</div></td>
						<td><div class='paging'>page <span class='page'></span> of <span class='topage'></span></div></td>
					</tr>
					</tbody>
				</table>
			</div>";
		return Billrun_Factory::config()->getConfigValue(self::$type . '.header', '');
	}
	
	protected function getInvoiceFooterContent() {
		//TODO : in future footer should came from config
		return "
			<div class='table'>
			  <table style='font-size:16px;'>
				<tbody><tr>
					<td>
					  <ul class='list-contacts'>
						<li><i class='fa fa-map-marker' aria-hidden='true'></i> " .  $this->getCompanyAddress() . "</li>

						<li><i class='fa fa-phone' aria-hidden='true'></i> " .  $this->getCompanyPhone() . "</li>

						<li><a href='" . $this->getCompanyWebsite() . "'><i class='fa fa-globe' aria-hidden='true'></i> " . $this->getCompanyWebsite() . "</a></li>

						<li><a href='mailto:" . $this->getCompanyEmail() . "'><i class='fa fa-at' aria-hidden='true'></i> " . $this->getCompanyEmail() . "</a></li>
					  </ul>
					</td>
					<td>
					  <p class='credentials'> <span class='text'>powered by</span> <img style='width:125px;object-fit:contain;' src='" . $this->billrun_footer_logo_path . "' alt=''></p>
					</td>
				  </tr>
				</tbody>
			  </table>
			</div>";
		return Billrun_Factory::config()->getConfigValue(self::$type . '.footer', '');
	}
	
}
