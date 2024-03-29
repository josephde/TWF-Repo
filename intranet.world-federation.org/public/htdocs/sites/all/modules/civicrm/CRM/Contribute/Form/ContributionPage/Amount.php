<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.2                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2012                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2012
 * $Id$
 *
 */

/**
 * form to process actions on the group aspect of Custom Data
 */
class CRM_Contribute_Form_ContributionPage_Amount extends CRM_Contribute_Form_ContributionPage {

  /**
   * contribution amount block.
   *
   * @var array
   * @access protected
   */
  protected $_amountBlock = array();

  /**
   * Constants for number of options for data types of multiple option.
   */
  CONST NUM_OPTION = 11;

  /**
   * Function to actually build the form
   *
   * @return void
   * @access public
   */
  public function buildQuickForm() {

    // do u want to allow a free form text field for amount
    $this->addElement('checkbox', 'is_allow_other_amount', ts('Allow other amounts'), NULL, array('onclick' => "minMax(this);showHideAmountBlock( this, 'is_allow_other_amount' );"));
    $this->add('text', 'min_amount', ts('Minimum Amount'), array('size' => 8, 'maxlength' => 8));
    $this->addRule('min_amount', ts('Please enter a valid money value (e.g. %1).', array(1 => CRM_Utils_Money::format('9.99', ' '))), 'money');

    $this->add('text', 'max_amount', ts('Maximum Amount'), array('size' => 8, 'maxlength' => 8));
    $this->addRule('max_amount', ts('Please enter a valid money value (e.g. %1).', array(1 => CRM_Utils_Money::format('99.99', ' '))), 'money');

    $default = array();
    $this->add('hidden', "price_field_id", '', array('id' => "price_field_id"));
    $this->add('hidden', "price_field_other", '', array('id' => "price_field_option"));
    for ($i = 1; $i <= self::NUM_OPTION; $i++) {
      // label
      $this->add('text', "label[$i]", ts('Label'), CRM_Core_DAO::getAttribute('CRM_Core_DAO_OptionValue', 'label'));

      $this->add('hidden', "price_field_value[$i]", '', array('id' => "price_field_value[$i]"));

      // value
      $this->add('text', "value[$i]", ts('Value'), CRM_Core_DAO::getAttribute('CRM_Core_DAO_OptionValue', 'value'));
      $this->addRule("value[$i]", ts('Please enter a valid money value (e.g. %1).', array(1 => CRM_Utils_Money::format('99.99', ' '))), 'money');

      // default
      $default[] = $this->createElement('radio', NULL, NULL, NULL, $i);
    }

    $this->addGroup($default, 'default');

    $this->addElement('checkbox', 'amount_block_is_active', ts('Contribution Amounts section enabled'), NULL, array('onclick' => "showHideAmountBlock( this, 'amount_block_is_active' );"));

    $this->addElement('checkbox', 'is_monetary', ts('Execute real-time monetary transactions'));

    $paymentProcessor = CRM_Core_PseudoConstant::paymentProcessor();
    $recurringPaymentProcessor = array();

    if (!empty($paymentProcessor)) {
      $paymentProcessorIds = implode(',', array_keys($paymentProcessor));
      $query = "
SELECT id
  FROM civicrm_payment_processor
 WHERE id IN ({$paymentProcessorIds})
   AND is_recur = 1";
      $dao = CRM_Core_DAO::executeQuery($query);
      while ($dao->fetch()) {
        $recurringPaymentProcessor[] = $dao->id;
      }
    }
    $this->assign('recurringPaymentProcessor', $recurringPaymentProcessor);
    if (count($paymentProcessor)) {
      $this->assign('paymentProcessor', $paymentProcessor);
    }

    $this->addCheckBox('payment_processor', ts('Payment Processor'),
      array_flip($paymentProcessor),
      NULL, NULL, NULL, NULL,
      array('&nbsp;&nbsp;', '&nbsp;&nbsp;', '&nbsp;&nbsp;', '<br/>')
    );


    //check if selected payment processor supports recurring payment
    if (!empty($recurringPaymentProcessor)) {
      $this->addElement('checkbox', 'is_recur', ts('Recurring contributions'), NULL,
        array('onclick' => "showHideByValue('is_recur',true,'recurFields','table-row','radio',false); showRecurInterval( );")
      );
      $this->addCheckBox('recur_frequency_unit', ts('Supported recurring units'),
        CRM_Core_OptionGroup::values('recur_frequency_units', FALSE, FALSE, FALSE, NULL, 'name'),
        NULL, NULL, NULL, NULL,
        array('&nbsp;&nbsp;', '&nbsp;&nbsp;', '&nbsp;&nbsp;', '<br/>')
      );
      $this->addElement('checkbox', 'is_recur_interval', ts('Support recurring intervals'));
    }

    // add pay later options
    $this->addElement('checkbox', 'is_pay_later', ts('Pay later option'),
      NULL, array('onclick' => "payLater(this);")
    );
    $this->addElement('textarea', 'pay_later_text', ts('Pay later label'),
      CRM_Core_DAO::getAttribute('CRM_Contribute_DAO_ContributionPage', 'pay_later_text'),
      FALSE
    );
    $this->addElement('textarea', 'pay_later_receipt', ts('Pay later instructions'),
      CRM_Core_DAO::getAttribute('CRM_Contribute_DAO_ContributionPage', 'pay_later_receipt'),
      FALSE
    );
    // add price set fields
    $price = CRM_Price_BAO_Set::getAssoc(FALSE, 'CiviContribute');
    if (CRM_Utils_System::isNull($price)) {
      $this->assign('price', FALSE);
    }
    else {
      $this->assign('price', TRUE);
    }
    $this->add('select', 'price_set_id', ts('Price Set'),
      array(
        '' => ts('- none -')) + $price,
      NULL, array('onchange' => "showHideAmountBlock( this.value, 'price_set_id' );")
    );
    //CiviPledge fields.
    $config = CRM_Core_Config::singleton();
    if (in_array('CiviPledge', $config->enableComponents)) {
      $this->assign('civiPledge', TRUE);
      $this->addElement('checkbox', 'is_pledge_active', ts('Pledges'),
        NULL, array('onclick' => "showHideAmountBlock( this, 'is_pledge_active' ); return showHideByValue('is_pledge_active',true,'pledgeFields','table-row','radio',false);")
      );
      $this->addCheckBox('pledge_frequency_unit', ts('Supported pledge frequencies'),
        CRM_Core_OptionGroup::values('recur_frequency_units', FALSE, FALSE, FALSE, NULL, 'name'),
        NULL, NULL, NULL, NULL,
        array('&nbsp;&nbsp;', '&nbsp;&nbsp;', '&nbsp;&nbsp;', '<br/>')
      );
      $this->addElement('checkbox', 'is_pledge_interval', ts('Allow frequency intervals'));
      $this->addElement('text', 'initial_reminder_day', ts('Send payment reminder'), array('size' => 3));
      $this->addElement('text', 'max_reminders', ts('Send up to'), array('size' => 3));
      $this->addElement('text', 'additional_reminder_day', ts('Send additional reminders'), array('size' => 3));
    }

    //add currency element.
    $this->addCurrency('currency', ts('Currency'));

    $this->addFormRule(array('CRM_Contribute_Form_ContributionPage_Amount', 'formRule'), $this);

    parent::buildQuickForm();
  }

  /**
   * This function sets the default values for the form. Note that in edit/view mode
   * the default values are retrieved from the database
   *
   * @access public
   *
   * @return void
   */
  function setDefaultValues() {
    $defaults = parent::setDefaultValues();
    $title = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_ContributionPage', $this->_id, 'title');
    CRM_Utils_System::setTitle(ts('Contribution Amounts (%1)', array(1 => $title)));

    if (!CRM_Utils_Array::value('pay_later_text', $defaults)) {
      $defaults['pay_later_text'] = ts('I will send payment by check');
    }

    if (CRM_Utils_Array::value('amount_block_is_active', $defaults)) {

      if ($priceSetId = CRM_Price_BAO_Set::getFor('civicrm_contribution_page', $this->_id, NULL)) {
        if ($isQuick = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_Set', $priceSetId, 'is_quick_config')) {
          $this->assign('isQuick', $isQuick);
          //$priceField = CRM_Core_DAO::getFieldValue( 'CRM_Price_DAO_Field', $priceSetId, 'id', 'price_set_id' );
          $options          = $pFIDs = array();
          $priceFieldParams = array('price_set_id' => $priceSetId);
          $priceFields      = CRM_Core_DAO::commonRetrieveAll('CRM_Price_DAO_Field', 'price_set_id', $priceSetId, $pFIDs, $return = array('html_type', 'name', 'is_active'));
          foreach ($priceFields as $priceField) {
            if ($priceField['id'] && $priceField['html_type'] == 'Radio' && $priceField['name'] == 'contribution_amount') {
              $defaults['price_field_id'] = $priceField['id'];
              $priceFieldOptions = CRM_Price_BAO_FieldValue::getValues($priceField['id'], $options, 'id', 1);
              $countRow = 0;
              foreach ($options as $optionId => $optionValue) {
                $countRow++;
                $defaults['value'][$countRow] = $optionValue['amount'];
                $defaults['label'][$countRow] = $optionValue['label'];
                $defaults['name'][$countRow] = $optionValue['name'];
                $defaults['weight'][$countRow] = $optionValue['weight'];

                $defaults["price_field_value"][$countRow] = $optionValue['id'];
                if ($optionValue['is_default']) {
                  $defaults['default'] = $countRow;
                }
              }
            }
            elseif ($priceField['id'] && $priceField['html_type'] == 'Text' && $priceField['name'] = 'other_amount' && $priceField['is_active']) {
              $defaults['price_field_other'] = $priceField['id'];
            }
          }
        }
      }

      if (CRM_Utils_Array::value('value', $defaults) && is_array($defaults['value'])) {

        // CRM-4038: fix value display
        foreach ($defaults['value'] as & $amount) {
          $amount = trim(CRM_Utils_Money::format($amount, ' '));
        }
      }
    }

    // fix the display of the monetary value, CRM-4038
    if (isset($defaults['min_amount'])) {
      $defaults['min_amount'] = CRM_Utils_Money::format($defaults['min_amount'], NULL, '%a');
    }
    if (isset($defaults['max_amount'])) {
      $defaults['max_amount'] = CRM_Utils_Money::format($defaults['max_amount'], NULL, '%a');
    }

    if (CRM_Utils_Array::value('payment_processor', $defaults)) {
      $defaults['payment_processor'] = array_fill_keys(explode(CRM_Core_DAO::VALUE_SEPARATOR,
          $defaults['payment_processor']
        ), '1');
    }
    return $defaults;
  }

  /**
   * global form rule
   *
   * @param array $fields  the input form values
   * @param array $files   the uploaded files if any
   * @param array $options additional user data
   *
   * @return true if no errors, else array of errors
   * @access public
   * @static
   */
  static
  function formRule($fields, $files, $self) {
    $errors = array();
    //as for separate membership payment we has to have
    //contribution amount section enabled, hence to disable it need to
    //check if separate membership payment enabled,
    //if so disable first separate membership payment option
    //then disable contribution amount section. CRM-3801,

    $membershipBlock = new CRM_Member_DAO_MembershipBlock();
    $membershipBlock->entity_table = 'civicrm_contribution_page';
    $membershipBlock->entity_id = $self->_id;
    $membershipBlock->is_active = 1;
    $hasMembershipBlk = FALSE;
    if ($membershipBlock->find(TRUE)) {
      if (CRM_Utils_Array::value('amount_block_is_active', $fields) &&
        ($setID = CRM_Price_BAO_Set::getFor('civicrm_contribution_page', $self->_id, NULL, 1))
      ) {
        $extends = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_Set', $setID, 'extends');
        if ($extends && $extends == CRM_Core_Component::getComponentID('CiviMember')) {
          $errors['amount_block_is_active'] = ts('You cannot use a Membership Price Set when the Contribution Amounts section is enabled. Click the Memberships tab above, and select your Membership Price Set on that form. Membership Price Sets may include additional fields for non-membership options that require an additional fee (e.g. magazine subscription) or an additional voluntary contribution.');
          return $errors;
        }
      }
      $hasMembershipBlk = TRUE;
      if ($membershipBlock->is_separate_payment && !$fields['amount_block_is_active']) {
        $errors['amount_block_is_active'] = ts('To disable Contribution Amounts section you need to first disable Separate Membership Payment option from Membership Settings.');
      }
    }

    $minAmount = CRM_Utils_Array::value('min_amount', $fields);
    $maxAmount = CRM_Utils_Array::value('max_amount', $fields);
    if (!empty($minAmount) && !empty($maxAmount)) {
      $minAmount = CRM_Utils_Rule::cleanMoney($minAmount);
      $maxAmount = CRM_Utils_Rule::cleanMoney($maxAmount);
      if ((float ) $minAmount > (float ) $maxAmount) {
        $errors['min_amount'] = ts('Minimum Amount should be less than Maximum Amount');
      }
    }

    if (isset($fields['is_pay_later'])) {
      if (empty($fields['pay_later_text'])) {
        $errors['pay_later_text'] = ts('Please enter the text for the \'pay later\' checkbox displayed on the contribution form.');
      }
      if (empty($fields['pay_later_receipt'])) {
        $errors['pay_later_receipt'] = ts('Please enter the instructions to be sent to the contributor when they choose to \'pay later\'.');
      }
    }

    // don't allow price set w/ membership signup, CRM-5095
    if ($priceSetId = CRM_Utils_Array::value('price_set_id', $fields)) {
      // don't allow price set w/ membership.
      if ($hasMembershipBlk) {
        $errors['price_set_id'] = ts('You cannot enable both a Contribution Price Set and Membership Signup on the same online contribution page.');
      }
    }
    else {
      if (isset($fields['is_recur'])) {
        if (empty($fields['recur_frequency_unit'])) {
          $errors['recur_frequency_unit'] = ts('At least one recurring frequency option needs to be checked.');
        }
      }

      // validation for pledge fields.
      if (CRM_Utils_array::value('is_pledge_active', $fields)) {
        if (empty($fields['pledge_frequency_unit'])) {
          $errors['pledge_frequency_unit'] = ts('At least one pledge frequency option needs to be checked.');
        }
        if (CRM_Utils_array::value('is_recur', $fields)) {
          $errors['is_recur'] = ts('You cannot enable both Recurring Contributions AND Pledges on the same online contribution page.');
        }
      }

      // If Contribution amount section is enabled, then
      // Allow other amounts must be enabeld OR the Fixed Contribution
      // Contribution options must contain at least one set of values.
      if (CRM_Utils_Array::value('amount_block_is_active', $fields)) {
        if (!CRM_Utils_Array::value('is_allow_other_amount', $fields) &&
          !$priceSetId
        ) {
          //get the values of amount block
          $values = CRM_Utils_Array::value('value', $fields);
          $isSetRow = FALSE;
          for ($i = 1; $i < self::NUM_OPTION; $i++) {
            if ((isset($values[$i]) && (strlen(trim($values[$i])) > 0))) {
              $isSetRow = TRUE;
            }
          }
          if (!$isSetRow) {
            $errors['amount_block_is_active'] = ts('If you want to enable the \'Contribution Amounts section\', you need to either \'Allow Other Amounts\' and/or enter at least one row in the \'Fixed Contribution Amounts\' table.');
          }
        }
      }
    }

    if (CRM_Utils_Array::value('is_recur_interval', $fields)) {
      $paymentProcessorType = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_PaymentProcessor',
        $fields['payment_processor'],
        'payment_processor_type'
      );
      if ($paymentProcessorType == 'Google_Checkout') {
        $errors['is_recur_interval'] = ts('Google Checkout does not support recurring intervals');
      }
    }

    return $errors;
  }

  /**
   * Process the form
   *
   * @return void
   * @access public
   */
  public function postProcess() {
    // get the submitted form values.
    $params = $this->controller->exportValues($this->_name);
    if (array_key_exists('payment_processor', $params)) {
      if (array_key_exists(CRM_Core_DAO::getFieldValue('CRM_Core_DAO_PaymentProcessor', 'AuthNet',
            'id', 'payment_processor_type'
          ),
          CRM_Utils_Array::value('payment_processor', $params)
        )) {
        CRM_Core_Session::setStatus(ts(' Please note that the Authorize.net payment processor only allows recurring contributions and auto-renew memberships with payment intervals from 7-365 days or 1-12 months (i.e. not greater than 1 year).'));
      }
    }

    // check for price set.
    $priceSetID = CRM_Utils_Array::value('price_set_id', $params);

    // get required fields.
    $fields = array(
      'id' => $this->_id,
      'is_recur' => FALSE,
      'min_amount' => "null",
      'max_amount' => "null",
      'is_monetary' => FALSE,
      'is_pay_later' => FALSE,
      'is_recur_interval' => FALSE,
      'recur_frequency_unit' => "null",
      'default_amount_id' => "null",
      'is_allow_other_amount' => FALSE,
      'amount_block_is_active' => FALSE,
    );
    $resetFields = array();
    if ($priceSetID) {
      $resetFields = array('min_amount', 'max_amount', 'is_allow_other_amount');
    }

    if (!CRM_Utils_Array::value('is_recur', $params)) {
      $resetFields = array_merge($resetFields, array('is_recur_interval', 'recur_frequency_unit'));
    }

    foreach ($fields as $field => $defaultVal) {
      $val = CRM_Utils_Array::value($field, $params, $defaultVal);
      if (in_array($field, $resetFields)) {
        $val = $defaultVal;
      }

      if (in_array($field, array(
        'min_amount', 'max_amount'))) {
        $val = CRM_Utils_Rule::cleanMoney($val);
      }

      $params[$field] = $val;
    }

    if ($params['is_recur']) {
      $params['recur_frequency_unit'] = implode(CRM_Core_DAO::VALUE_SEPARATOR,
        array_keys($params['recur_frequency_unit'])
      );
      $params['is_recur_interval'] = CRM_Utils_Array::value('is_recur_interval', $params, FALSE);
    }

    if (array_key_exists('payment_processor', $params) &&
      !CRM_Utils_System::isNull($params['payment_processor'])
    ) {
      $params['payment_processor'] = implode(CRM_Core_DAO::VALUE_SEPARATOR, array_keys($params['payment_processor']));
    }
    else {
      $params['payment_processor'] = 'null';
    }

    $contributionPage = CRM_Contribute_BAO_ContributionPage::create($params);
    $contributionPageID = $contributionPage->id;

    // prepare for data cleanup.
    $deleteAmountBlk = $deletePledgeBlk = $deletePriceSet = FALSE;
    if ($this->_priceSetID) {
      $deletePriceSet = TRUE;
    }
    if ($this->_pledgeBlockID) {
      $deletePledgeBlk = TRUE;
    }
    if (!empty($this->_amountBlock)) {
      $deleteAmountBlk = TRUE;
    }

    if ($contributionPageID) {

      if (CRM_Utils_Array::value('amount_block_is_active', $params)) {
        // handle price set.
        if ($priceSetID) {
          // add/update price set.
          $deletePriceSet = FALSE;
          if (CRM_Utils_Array::value('price_field_id', $params) || CRM_Utils_Array::value('price_field_other', $params) ) {
            $deleteAmountBlk = TRUE;
          }

          CRM_Price_BAO_Set::addTo('civicrm_contribution_page', $contributionPageID, $priceSetID);
        }
        else {

          $deletePriceSet = FALSE;
          // process contribution amount block
          $deleteAmountBlk = FALSE;

          $labels  = CRM_Utils_Array::value('label', $params);
          $values  = CRM_Utils_Array::value('value', $params);
          $default = CRM_Utils_Array::value('default', $params);

          $options = array();
          for ($i = 1; $i < self::NUM_OPTION; $i++) {
            if (isset($values[$i]) &&
              (strlen(trim($values[$i])) > 0)
            ) {
              $options[] = array('label' => trim($labels[$i]),
                'value' => CRM_Utils_Rule::cleanMoney(trim($values[$i])),
                'weight' => $i,
                'is_active' => 1,
                'is_default' => $default == $i,
              );
            }
          }
            /* || CRM_Utils_Array::value( 'price_field_value', $params )|| CRM_Utils_Array::value( 'price_field_other', $params )*/
          if (!empty($options) || CRM_Utils_Array::value('is_allow_other_amount', $params)) {
            $noContriAmount = NULL;
            $usedPriceSetId = CRM_Price_BAO_Set::getFor('civicrm_contribution_page', $this->_id, 3);
            if (!(CRM_Utils_Array::value('price_field_id', $params) || CRM_Utils_Array::value('price_field_other', $params)) && !$usedPriceSetId) {
              $pageTitle = strtolower(CRM_Utils_String::munge($this->_values['title'], '_', 245));
              $setParams['title'] = $this->_values['title'];
              if (!CRM_Core_DAO::getFieldValue('CRM_Price_BAO_Set', $pageTitle, 'id', 'name')) {
                $setParams['name'] = $pageTitle;
              }    
              elseif (!CRM_Core_DAO::getFieldValue('CRM_Price_BAO_Set', $pageTitle . '_' . $this->_id, 'id', 'name')) {
                $setParams['name'] = $pageTitle . '_' . $this->_id;
              }
              else {
                $timeSec = explode(".", microtime(true));
                $setParams['name'] = $pageTitle . '_' . date('is', $timeSec[0]) . $timeSec[1];
              }
              $setParams['is_quick_config'] = 1;
              $setParams['extends'] = CRM_Core_Component::getComponentID('CiviContribute');
              $priceSet = CRM_Price_BAO_Set::create($setParams);
              $priceSetId = $priceSet->id;
            }
            elseif ($usedPriceSetId && !CRM_Utils_Array::value('price_field_id', $params)) {
              $priceSetId = $usedPriceSetId;
            }
            else {
              if ($priceFieldId = CRM_Utils_Array::value('price_field_id', $params)) {
                foreach ($params['price_field_value'] as $arrayID => $fieldValueID) {
                  if (empty($params['label'][$arrayID]) && empty($params['value'][$arrayID]) && !empty($fieldValueID)) {
                    CRM_Price_BAO_FieldValue::setIsActive($fieldValueID, '0');
                    unset($params['price_field_value'][$arrayID]);
                  }
                }
                if (implode('', $params['price_field_value'])) {
                  $fieldParams['id'] = CRM_Utils_Array::value('price_field_id', $params);
                  $fieldParams['option_id'] = $params['price_field_value'];
                }
                else {
                  $noContriAmount = 0;
                  CRM_Price_BAO_Field::setIsActive($priceFieldId, '0');
                }
              }
              else $priceFieldId = CRM_Utils_Array::value('price_field_other', $params);
              $priceSetId = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_Field', $priceFieldId, 'price_set_id');
            }
            CRM_Price_BAO_Set::addTo('civicrm_contribution_page', $this->_id, $priceSetId);
            if (!empty($options)) {
              $editedFieldParams = array(
                                         'price_set_id' => $priceSetId,
                                         'name' => 'contribution_amount',
                                         );
              $editedResults = array();
              $noContriAmount = 1;
              CRM_Price_BAO_Field::retrieve($editedFieldParams, $editedResults);
              if (!CRM_Utils_Array::value('id', $editedResults)) {
                $fieldParams['name'] = strtolower(CRM_Utils_String::munge("Contribution Amount", '_', 245));
                $fieldParams['label'] = "Contribution Amount";
              }
              else {
                $fieldParams['id'] = CRM_Utils_Array::value('id', $editedResults);
              }

              $fieldParams['price_set_id'] = $priceSetId;
              $fieldParams['is_active'] = 1;
              $fieldParams['weight'] = 2;

              if (CRM_Utils_Array::value('is_allow_other_amount', $params)) {
                $fieldParams['is_required'] = 0;
              }
              else {
                $fieldParams['is_required'] = 1;
              }
              $fieldParams['html_type'] = 'Radio';
              $fieldParams['option_label'] = $params['label'];
              $fieldParams['option_amount'] = $params['value'];
              foreach ($options as $value) {
                $fieldParams['option_weight'][$value['weight']] = $value['weight'];
              }
              $fieldParams['default_option'] = $params['default'];
              $priceField = CRM_Price_BAO_Field::create($fieldParams);
            }
            if (CRM_Utils_Array::value('is_allow_other_amount', $params) && !CRM_Utils_Array::value('price_field_other', $params)) {
              $editedFieldParams = array(
                                         'price_set_id' => $priceSetId,
                                         'name' => 'other_amount',
                                         );
              $editedResults = array();

              CRM_Price_BAO_Field::retrieve($editedFieldParams, $editedResults);

              if (!$priceFieldID = CRM_Utils_Array::value('id', $editedResults)) {
                $fieldParams = array( 'name'               => 'other_amount',
                                      'label'              => 'Other Amount',
                                      'price_set_id'       => $priceSetId,
                                      'html_type'          => 'Text',
                                      'is_display_amounts' => 0,
                                      'weight'             => 3,
                                      );
                $fieldParams['option_weight'][1] = 1;
                $fieldParams['option_amount'][1] = 1;
                if (!$noContriAmount) {
                  $fieldParams['is_required'] = 1;
                  $fieldParams['option_label'][1] = 'Contribution Amount'; 
                } else {
                  $fieldParams['is_required'] = 0;
                  $fieldParams['option_label'][1] = 'Other Amount';
                }

                $priceField = CRM_Price_BAO_Field::create($fieldParams);
              } else {
                if (!CRM_Utils_Array::value('is_active', $editedResults)) {
                  CRM_Price_BAO_Field::setIsActive($priceFieldID, '1');
                }
              }
            } elseif (!CRM_Utils_Array::value('is_allow_other_amount', $params) && CRM_Utils_Array::value('price_field_other', $params)) {
              CRM_Price_BAO_Field::setIsActive($params['price_field_other'], '0');
            } elseif ($priceFieldID = CRM_Utils_Array::value('price_field_other', $params)) {
              $priceFieldValueID = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_FieldValue', $priceFieldID, 'id', 'price_field_id' );
              if (!$noContriAmount) {
                CRM_Core_DAO::setFieldValue('CRM_Price_DAO_Field', $priceFieldID, 'is_required', 1);
                CRM_Core_DAO::setFieldValue('CRM_Price_DAO_FieldValue', $priceFieldValueID, 'label', 'Contribution Amount' );
              } else {
                CRM_Core_DAO::setFieldValue('CRM_Price_DAO_Field', $priceFieldID, 'is_required', 0 );
                CRM_Core_DAO::setFieldValue('CRM_Price_DAO_FieldValue', $priceFieldValueID, 'label', 'Other Amount' );
              }
            }
          }

          if (CRM_Utils_Array::value('is_pledge_active', $params)) {
            $deletePledgeBlk = FALSE;
            $pledgeBlockParams = array(
              'entity_id' => $contributionPageID,
              'entity_table' => ts('civicrm_contribution_page'),
            );
            if ($this->_pledgeBlockID) {
              $pledgeBlockParams['id'] = $this->_pledgeBlockID;
            }
            $pledgeBlock = array(
              'pledge_frequency_unit', 'max_reminders',
              'initial_reminder_day', 'additional_reminder_day',
            );
            foreach ($pledgeBlock as $key) {
              $pledgeBlockParams[$key] = CRM_Utils_Array::value($key, $params);
            }
            $pledgeBlockParams['is_pledge_interval'] = CRM_Utils_Array::value('is_pledge_interval',
              $params, FALSE
            );
            // create pledge block.
            CRM_Pledge_BAO_PledgeBlock::create($pledgeBlockParams);
          }
        }
      }
      else {
        if (CRM_Utils_Array::value('price_field_id', $params) || CRM_Utils_Array::value('price_field_other', $params)) {
          $usedPriceSetId = CRM_Price_BAO_Set::getFor('civicrm_contribution_page', $this->_id, 3);
          if ($usedPriceSetId) {
            if (CRM_Utils_Array::value('price_field_id', $params)) {
              CRM_Price_BAO_Field::setIsActive($params['price_field_id'], '0');
            }
            if (CRM_Utils_Array::value('price_field_other', $params)) {
              CRM_Price_BAO_Field::setIsActive($params['price_field_other'], '0');
            }
          }
          else {
            $deleteAmountBlk = TRUE;
            $deletePriceSet = TRUE;
          }
        }
      }

      // delete pledge block.
      if ($deletePledgeBlk) {
        CRM_Pledge_BAO_PledgeBlock::deletePledgeBlock($this->_pledgeBlockID);
      }

      // delete previous price set.
      if ($deletePriceSet) {
        CRM_Price_BAO_Set::removeFrom('civicrm_contribution_page', $contributionPageID);
      }
      
      if ($deleteAmountBlk ) {   
        $priceField = CRM_Utils_Array::value('price_field_id', $params)?$params['price_field_id']:CRM_Utils_Array::value('price_field_other', $params);
        if ($priceField) {
          $priceSetID = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_Field', $priceField, 'price_set_id');
          CRM_Price_BAO_Set::setIsQuickConfig($priceSetID,0);
        }
      }
    }
    parent::endPostProcess();
  }

  /**
   * Return a descriptive name for the page, used in wizard header
   *
   * @return string
   * @access public
   */
  public function getTitle() {
    return ts('Amounts');
  }
}

