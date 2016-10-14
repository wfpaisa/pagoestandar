<?php

if (!defined('_PS_VERSION_'))
	exit;

class PagoEnLinea extends PaymentModule
{
	protected $_html = '';
	protected $_postErrors = array();

	public $details;
	public $owner;
	public $address;
	public $extra_mail_vars;
	public function __construct()
	{
		$this->name = 'pagoenlinea';
		$this->tab = 'payments_gateways';
		$this->version = '1.1.2';
		$this->author = 'PrestaShop';
		$this->controllers = array('payment', 'validation');
		$this->is_eu_compatible = 1;

		$this->currencies = true;
		$this->currencies_mode = 'checkbox';

		$config = Configuration::getMultiple(array('PAGO_EN_LINEA_DETAILS', 'PAGO_EN_LINEA_OWNER', 'PAGO_EN_LINEA_ADDRESS'));
		if (!empty($config['PAGO_EN_LINEA_OWNER']))
			$this->owner = $config['PAGO_EN_LINEA_OWNER'];
		if (!empty($config['PAGO_EN_LINEA_DETAILS']))
			$this->details = $config['PAGO_EN_LINEA_DETAILS'];
		if (!empty($config['PAGO_EN_LINEA_ADDRESS']))
			$this->address = $config['PAGO_EN_LINEA_ADDRESS'];

		$this->bootstrap = true;
		parent::__construct();

		$this->displayName = $this->l('Pago en línea');
		$this->description = $this->l('Accept payments for your products via pago en linea transfer.');
		$this->confirmUninstall = $this->l('Are you sure about removing these details?');
		$this->ps_versions_compliancy = array('min' => '1.6', 'max' => '1.6.99.99');

		if (!isset($this->owner) || !isset($this->details) || !isset($this->address))
			$this->warning = $this->l('Account owner and account details must be configured before using this module.');
		if (!count(Currency::checkPaymentCurrencies($this->id)))
			$this->warning = $this->l('No currency has been set for this module.');

		$this->extra_mail_vars = array(
										'{pagoenlinea_owner}' => Configuration::get('PAGO_EN_LINEA_OWNER'),
										'{pagoenlinea_details}' => nl2br(Configuration::get('PAGO_EN_LINEA_DETAILS')),
										'{pagoenlinea_address}' => nl2br(Configuration::get('PAGO_EN_LINEA_ADDRESS'))
										);
	}

	public function install()
	{
		if (!parent::install() || !$this->registerHook('payment') || ! $this->registerHook('displayPaymentEU') || !$this->registerHook('paymentReturn'))
			return false;
		return true;
	}

	public function uninstall()
	{
		if (!Configuration::deleteByName('PAGO_EN_LINEA_DETAILS')
				|| !Configuration::deleteByName('PAGO_EN_LINEA_OWNER')
				|| !Configuration::deleteByName('PAGO_EN_LINEA_ADDRESS')
				|| !parent::uninstall())
			return false;
		return true;
	}

	protected function _postValidation()
	{
		if (Tools::isSubmit('btnSubmit'))
		{
			if (!Tools::getValue('PAGO_EN_LINEA_DETAILS'))
				$this->_postErrors[] = $this->l('Account details are required.');
			elseif (!Tools::getValue('PAGO_EN_LINEA_OWNER'))
				$this->_postErrors[] = $this->l('Account owner is required.');
		}
	}

	protected function _postProcess()
	{
		if (Tools::isSubmit('btnSubmit'))
		{
			Configuration::updateValue('PAGO_EN_LINEA_DETAILS', Tools::getValue('PAGO_EN_LINEA_DETAILS'));
			Configuration::updateValue('PAGO_EN_LINEA_OWNER', Tools::getValue('PAGO_EN_LINEA_OWNER'));
			Configuration::updateValue('PAGO_EN_LINEA_ADDRESS', Tools::getValue('PAGO_EN_LINEA_ADDRESS'));
		}
		$this->_html .= $this->displayConfirmation($this->l('Settings updated'));
	}

	protected function _displayPagoEnLinea()
	{
		return $this->display(__FILE__, 'infos.tpl');
	}

	public function getContent()
	{
		if (Tools::isSubmit('btnSubmit'))
		{
			$this->_postValidation();
			if (!count($this->_postErrors))
				$this->_postProcess();
			else
				foreach ($this->_postErrors as $err)
					$this->_html .= $this->displayError($err);
		}
		else
			$this->_html .= '<br />';

		$this->_html .= $this->_displayPagoEnLinea();
		$this->_html .= $this->renderForm();

		return $this->_html;
	}

	public function hookPayment($params)
	{
		if (!$this->active)
			return;
		if (!$this->checkCurrency($params['cart']))
			return;

		$this->smarty->assign(array(
			'this_path' => $this->_path,
			'this_path_bw' => $this->_path,
			'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->name.'/'
		));
		return $this->display(__FILE__, 'payment.tpl');
	}

	public function hookDisplayPaymentEU($params)
	{
		if (!$this->active)
			return;

		if (!$this->checkCurrency($params['cart']))
			return;

		$payment_options = array(
			'cta_text' => $this->l('Pay by Pago Estandar'),
			'logo' => Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/pagoenlinea.jpg'),
			'action' => $this->context->link->getModuleLink($this->name, 'validation', array(), true)
		);

		return $payment_options;
	}

	public function hookPaymentReturn($params)
	{
		if (!$this->active)
			return;

		$state = $params['objOrder']->getCurrentState();
		if (in_array($state, array(Configuration::get('PS_OS_PAGOENLINEA'), Configuration::get('PS_OS_OUTOFSTOCK'), Configuration::get('PS_OS_OUTOFSTOCK_UNPAID'))))
		{
			$this->smarty->assign(array(
				'total_to_pay' => Tools::displayPrice($params['total_to_pay'], $params['currencyObj'], false),
				'pagoenlineaDetails' => Tools::nl2br($this->details),
				'pagoenlineaAddress' => Tools::nl2br($this->address),
				'pagoenlineaOwner' => $this->owner,
				'status' => 'ok',
				'id_order' => $params['objOrder']->id
			));
			if (isset($params['objOrder']->reference) && !empty($params['objOrder']->reference))
				$this->smarty->assign('reference', $params['objOrder']->reference);
		}
		else
			$this->smarty->assign('status', 'failed');
		return $this->display(__FILE__, 'payment_return.tpl');
	}

	public function checkCurrency($cart)
	{
		$currency_order = new Currency($cart->id_currency);
		$currencies_module = $this->getCurrency($cart->id_currency);

		if (is_array($currencies_module))
			foreach ($currencies_module as $currency_module)
				if ($currency_order->id == $currency_module['id_currency'])
					return true;
		return false;
	}

	public function renderForm()
	{
		$fields_form = array(
			'form' => array(
				'legend' => array(
					'title' => $this->l('Contact details'),
					'icon' => 'icon-envelope'
				),
				'input' => array(
					array(
						'type' => 'text',
						'label' => $this->l('Account owner'),
						'name' => 'PAGO_EN_LINEA_OWNER',
						'required' => true
					),
					array(
						'type' => 'textarea',
						'label' => $this->l('Details'),
						'name' => 'PAGO_EN_LINEA_DETAILS',
						'desc' => $this->l('Such as bank branch, IBAN number, BIC, etc.'),
						'required' => true
					),
					array(
						'type' => 'textarea',
						'label' => $this->l('Bank address'),
						'name' => 'PAGO_EN_LINEA_ADDRESS',
						'required' => true
					),
				),
				'submit' => array(
					'title' => $this->l('Save'),
				)
			),
		);

		$helper = new HelperForm();
		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
		$helper->default_form_language = $lang->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
		$this->fields_form = array();
		$helper->id = (int)Tools::getValue('id_carrier');
		$helper->identifier = $this->identifier;
		$helper->submit_action = 'btnSubmit';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars = array(
			'fields_value' => $this->getConfigFieldsValues(),
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id
		);

		return $helper->generateForm(array($fields_form));
	}

	public function getConfigFieldsValues()
	{
		return array(
			'PAGO_EN_LINEA_DETAILS' => Tools::getValue('PAGO_EN_LINEA_DETAILS', Configuration::get('PAGO_EN_LINEA_DETAILS')),
			'PAGO_EN_LINEA_OWNER' => Tools::getValue('PAGO_EN_LINEA_OWNER', Configuration::get('PAGO_EN_LINEA_OWNER')),
			'PAGO_EN_LINEA_ADDRESS' => Tools::getValue('PAGO_EN_LINEA_ADDRESS', Configuration::get('PAGO_EN_LINEA_ADDRESS')),
		);
	}
}