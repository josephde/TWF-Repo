<?php
// $Id$

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
class CRM_Contribute_BAO_Contribution extends CRM_Contribute_DAO_Contribution {

  /**
   * static field for all the contribution information that we can potentially import
   *
   * @var array
   * @static
   */
  static $_importableFields = NULL;

  /**
   * static field for all the contribution information that we can potentially export
   *
   * @var array
   * @static
   */
  static $_exportableFields = NULL;

  /**
   * field for all the objects related to this contribution
   * @var array of objects (e.g membership object, participant object)
   */
  public $_relatedObjects = array();

  /**
   * field for the component - either 'event' (participant) or 'contribute'
   * (any item related to a contribution page e.g. membership, pledge, contribution)
   * This is used for composing messages because they have dependency on the
   * contribution_page or event page - although over time we may eliminate that
   *
   * @var string component or event
   */
  public $_component = NULL;

  /*
   * construct method
   */
  function __construct() {
    parent::__construct();
  }

  /**
   * takes an associative array and creates a contribution object
   *
   * the function extract all the params it needs to initialize the create a
   * contribution object. the params array could contain additional unused name/value
   * pairs
   *
   * @param array  $params (reference ) an assoc array of name/value pairs
   * @param array $ids    the array that holds all the db ids
   *
   * @return object CRM_Contribute_BAO_Contribution object
   * @access public
   * @static
   */
  static function add(&$params, &$ids) {
    if (empty($params)) {
      return;
    }

    $duplicates = array();
    if (self::checkDuplicate($params, $duplicates,
        CRM_Utils_Array::value('contribution', $ids)
      )) {
      $error = CRM_Core_Error::singleton();
      $d = implode(', ', $duplicates);
      $error->push(CRM_Core_Error::DUPLICATE_CONTRIBUTION,
        'Fatal',
        array($d),
        "Duplicate error - existing contribution record(s) have a matching Transaction ID or Invoice ID. Contribution record ID(s) are: $d"
      );
      return $error;
    }

    // first clean up all the money fields
    $moneyFields = array(
      'total_amount',
      'net_amount',
      'fee_amount',
      'non_deductible_amount',
    );
    //if priceset is used, no need to cleanup money
    if (CRM_UTils_Array::value('skipCleanMoney', $params)) {
      unset($moneyFields[0]);
    }

    foreach ($moneyFields as $field) {
      if (isset($params[$field])) {
        $params[$field] = CRM_Utils_Rule::cleanMoney($params[$field]);
      }
    }

    if (CRM_Utils_Array::value('payment_instrument_id', $params)) {
      $paymentInstruments = CRM_Contribute_PseudoConstant::paymentInstrument('name');
      if ($params['payment_instrument_id'] != array_search('Check', $paymentInstruments)) {
        $params['check_number'] = 'null';
      }
    }

    if (CRM_Utils_Array::value('contribution', $ids)) {
      CRM_Utils_Hook::pre('edit', 'Contribution', $ids['contribution'], $params);
    }
    else {
      CRM_Utils_Hook::pre('create', 'Contribution', NULL, $params);
    }

    $contribution = new CRM_Contribute_BAO_Contribution();
    $contribution->copyValues($params);

    $contribution->id = CRM_Utils_Array::value('contribution', $ids);

    if (!CRM_Utils_Rule::currencyCode($contribution->currency)) {
      $config = CRM_Core_Config::singleton();
      $contribution->currency = $config->defaultCurrency;
    }

    $result = $contribution->save();

    // Add financial_trxn details as part of fix for CRM-4724
    $contribution->trxn_result_code = CRM_Utils_Array::value('trxn_result_code', $params);
    $contribution->payment_processor = CRM_Utils_Array::value('payment_processor', $params);

    // Add soft_contribution details as part of fix for CRM-8908
    $contribution->soft_credit_to = CRM_Utils_Array::value('soft_credit_to', $params);

    // reset the group contact cache for this group
    CRM_Contact_BAO_GroupContactCache::remove();

    if (CRM_Utils_Array::value('contribution', $ids)) {
      CRM_Utils_Hook::post('edit', 'Contribution', $contribution->id, $contribution);
    }
    else {
      CRM_Utils_Hook::post('create', 'Contribution', $contribution->id, $contribution);
    }

    return $result;
  }

  /**
   * Given the list of params in the params array, fetch the object
   * and store the values in the values array
   *
   * @param array $params input parameters to find object
   * @param array $values output values of the object
   * @param array $ids    the array that holds all the db ids
   *
   * @return CRM_Contribute_BAO_Contribution|null the found object or null
   * @access public
   * @static
   */
  static function &getValues(&$params, &$values, &$ids) {
    if (empty($params)) {
      return NULL;
    }
    $contribution = new CRM_Contribute_BAO_Contribution();

    $contribution->copyValues($params);

    if ($contribution->find(TRUE)) {
      $ids['contribution'] = $contribution->id;

      CRM_Core_DAO::storeValues($contribution, $values);

      return $contribution;
    }
    return NULL;
  }

  /**
   * takes an associative array and creates a contribution object
   *
   * @param array $params (reference ) an assoc array of name/value pairs
   * @param array $ids    the array that holds all the db ids
   *
   * @return object CRM_Contribute_BAO_Contribution object
   * @access public
   * @static
   */
  static function &create(&$params, &$ids) {
    // FIXME: a cludgy hack to fix the dates to MySQL format
    $dateFields = array('receive_date', 'cancel_date', 'receipt_date', 'thankyou_date');
    foreach ($dateFields as $df) {
      if (isset($params[$df])) {
        $params[$df] = CRM_Utils_Date::isoToMysql($params[$df]);
      }
    }

    if (CRM_Utils_Array::value('contribution', $ids) &&
      !CRM_Utils_Array::value('softID', $params)
    ) {
      if ($softID = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_ContributionSoft', $ids['contribution'], 'id', 'contribution_id')) {
        $params['softID'] = $softID;
      }
    }

    $transaction = new CRM_Core_Transaction();

    // delete the soft credit record if no soft credit contact ID AND no PCP is set in the form
    if (CRM_Utils_Array::value('contribution', $ids) &&
      (!CRM_Utils_Array::value('soft_credit_to', $params) &&
        !CRM_Utils_Array::value('pcp_made_through_id', $params)
      ) &&
      CRM_Utils_Array::value('softID', $params)
    ) {
      $softCredit = new CRM_Contribute_DAO_ContributionSoft();
      $softCredit->id = $params['softID'];
      $softCredit->delete();
    }

    $contribution = self::add($params, $ids);

    if (is_a($contribution, 'CRM_Core_Error')) {
      $transaction->rollback();
      return $contribution;
    }

    $params['contribution_id'] = $contribution->id;

    if (CRM_Utils_Array::value('custom', $params) &&
      is_array($params['custom'])
    ) {
      CRM_Core_BAO_CustomValueTable::store($params['custom'], 'civicrm_contribution', $contribution->id);
    }

    $session = CRM_Core_Session::singleton();

    if (CRM_Utils_Array::value('note', $params)) {

      $noteParams = array(
        'entity_table' => 'civicrm_contribution',
        'note' => $params['note'],
        'entity_id' => $contribution->id,
        'contact_id' => $session->get('userID'),
        'modified_date' => date('Ymd'),
      );
      if (!$noteParams['contact_id']) {
        $noteParams['contact_id'] = $params['contact_id'];
      }

      CRM_Core_BAO_Note::add($noteParams,
        CRM_Utils_Array::value('note', $ids)
      );
    }

    // make entry in batch entity batch table
    if (CRM_Utils_Array::value('batch_id', $params)) {
      $entityParams = array(
        'batch_id' => $params['batch_id'],
        'entity_table' => 'civicrm_contribution',
        'entity_id' => $contribution->id,
      );
      // in some update cases we need to get extra fields - ie an update that doesn't pass in all these params
      $titleFields = array(
        'contact_id',
        'total_amount',
        'currency',
        'contribution_type_id',
      );
      $retrieverequired = 0;
      foreach ($titleFields as $titleField) {
        if(!isset($contribution->$titleField)){
          $retrieverequired = 1;
          break;
        }
      }
      if($retrieverequired == 1){
        $contribution->find(true);
      }
      CRM_Core_BAO_Batch::addBatchEntity($entityParams);
    }

    // check if activity record exist for this contribution, if
    // not add activity
    $activity = new CRM_Activity_DAO_Activity();
    $activity->source_record_id = $contribution->id;
    $activity->activity_type_id = CRM_Core_OptionGroup::getValue('activity_type',
      'Contribution',
      'name'
    );
    if (!$activity->find()) {
      CRM_Activity_BAO_Activity::addActivity($contribution, 'Offline');
    }

    // Handle soft credit and / or link to personal campaign page
    if (CRM_Utils_Array::value('soft_credit_to', $params) ||
      CRM_Utils_Array::value('pcp_made_through_id', $params)
    ) {
      $csParams = array();
      if ($id = CRM_Utils_Array::value('softID', $params)) {
        $csParams['id'] = $params['softID'];
      }

      $csParams['contribution_id'] = $contribution->id;
      // If pcp_made_through_id set, we define soft_credit_to contact based on selected PCP,
      // else use passed soft_credit_to
      if (CRM_Utils_Array::value('pcp_made_through_id', $params)) {
        $csParams['pcp_display_in_roll'] = $params['pcp_display_in_roll'] ? 1 : 0;
        foreach (array(
          'pcp_roll_nickname', 'pcp_personal_note') as $val) {
          $csParams[$val] = $params[$val];
        }

        $csParams['pcp_id'] = CRM_Utils_Array::value('pcp_made_through_id', $params);
        $csParams['contact_id'] = CRM_Core_DAO::getFieldValue('CRM_PCP_DAO_PCP',
          $csParams['pcp_id'], 'contact_id'
        );
      }
      else {
        $csParams['contact_id'] = $params['soft_credit_to'];
        $csParams['pcp_id'] = '';
      }

      // first stage: we register whole amount as credited to given person
      $csParams['amount'] = $contribution->total_amount;

      self::addSoftContribution($csParams);
    }

    $transaction->commit();

    // do not add to recent items for import, CRM-4399
    if (!CRM_Utils_Array::value('skipRecentView', $params)) {
      $url = CRM_Utils_System::url('civicrm/contact/view/contribution',
        "action=view&reset=1&id={$contribution->id}&cid={$contribution->contact_id}&context=home"
      );
      // in some update cases we need to get extra fields - ie an update that doesn't pass in all these params
      $titleFields = array(
        'contact_id',
        'total_amount',
        'currency',
        'contribution_type_id',
      );
      $retrieverequired = 0;
      foreach ($titleFields as $titleField) {
        if(!isset($contribution->$titleField)){
          $retrieverequired = 1;
          break;
        }
      }
      if($retrieverequired == 1){
        $contribution->find(true);
      }
      $contributionTypes = CRM_Contribute_PseudoConstant::contributionType();
      $title = CRM_Contact_BAO_Contact::displayName($contribution->contact_id) . ' - (' . CRM_Utils_Money::format($contribution->total_amount, $contribution->currency) . ' ' . ' - ' . $contributionTypes[$contribution->contribution_type_id] . ')';

      $recentOther = array();
      if (CRM_Core_Permission::checkActionPermission('CiviContribute', CRM_Core_Action::UPDATE)) {
        $recentOther['editUrl'] = CRM_Utils_System::url('civicrm/contact/view/contribution',
          "action=update&reset=1&id={$contribution->id}&cid={$contribution->contact_id}&context=home"
        );
      }

      if (CRM_Core_Permission::checkActionPermission('CiviContribute', CRM_Core_Action::DELETE)) {
        $recentOther['deleteUrl'] = CRM_Utils_System::url('civicrm/contact/view/contribution',
          "action=delete&reset=1&id={$contribution->id}&cid={$contribution->contact_id}&context=home"
        );
      }

      // add the recently created Contribution
      CRM_Utils_Recent::add($title,
        $url,
        $contribution->id,
        'Contribution',
        $contribution->contact_id,
        NULL,
        $recentOther
      );
    }

    return $contribution;
  }

  /**
   * Get the values for pseudoconstants for name->value and reverse.
   *
   * @param array   $defaults (reference) the default values, some of which need to be resolved.
   * @param boolean $reverse  true if we want to resolve the values in the reverse direction (value -> name)
   *
   * @return void
   * @access public
   * @static
   */
  static function resolveDefaults(&$defaults, $reverse = FALSE) {
    self::lookupValue($defaults, 'contribution_type', CRM_Contribute_PseudoConstant::contributionType(), $reverse);
    self::lookupValue($defaults, 'payment_instrument', CRM_Contribute_PseudoConstant::paymentInstrument(), $reverse);
    self::lookupValue($defaults, 'contribution_status', CRM_Contribute_PseudoConstant::contributionStatus(), $reverse);
    self::lookupValue($defaults, 'pcp', CRM_Contribute_PseudoConstant::pcPage(), $reverse);
  }

  /**
   * This function is used to convert associative array names to values
   * and vice-versa.
   *
   * This function is used by both the web form layer and the api. Note that
   * the api needs the name => value conversion, also the view layer typically
   * requires value => name conversion
   */
  static function lookupValue(&$defaults, $property, &$lookup, $reverse) {
    $id = $property . '_id';

    $src = $reverse ? $property : $id;
    $dst = $reverse ? $id : $property;

    if (!array_key_exists($src, $defaults)) {
      return FALSE;
    }

    $look = $reverse ? array_flip($lookup) : $lookup;

    if (is_array($look)) {
      if (!array_key_exists($defaults[$src], $look)) {
        return FALSE;
      }
    }
    $defaults[$dst] = $look[$defaults[$src]];
    return TRUE;
  }

  /**
   * Takes a bunch of params that are needed to match certain criteria and
   * retrieves the relevant objects. We'll tweak this function to be more
   * full featured over a period of time. This is the inverse function of
   * create.  It also stores all the retrieved values in the default array
   *
   * @param array $params   (reference ) an assoc array of name/value pairs
   * @param array $defaults (reference ) an assoc array to hold the name / value pairs
   *                        in a hierarchical manner
   * @param array $ids      (reference) the array that holds all the db ids
   *
   * @return object CRM_Contribute_BAO_Contribution object
   * @access public
   * @static
   */
  static function retrieve(&$params, &$defaults, &$ids) {
    $contribution = CRM_Contribute_BAO_Contribution::getValues($params, $defaults, $ids);
    return $contribution;
  }

  /**
   * combine all the importable fields from the lower levels object
   *
   * The ordering is important, since currently we do not have a weight
   * scheme. Adding weight is super important and should be done in the
   * next week or so, before this can be called complete.
   *
   * @return array array of importable Fields
   * @access public
   * @static
   */
  static function &importableFields($contacType = 'Individual', $status = TRUE) {
    if (!self::$_importableFields) {
      if (!self::$_importableFields) {
        self::$_importableFields = array();
      }

      if (!$status) {
        $fields = array('' => array('title' => ts('- do not import -')));
      }
      else {
        $fields = array('' => array('title' => ts('- Contribution Fields -')));
      }

      $note = CRM_Core_DAO_Note::import();
      $tmpFields = CRM_Contribute_DAO_Contribution::import();
      unset($tmpFields['option_value']);
      $optionFields = CRM_Core_OptionValue::getFields($mode = 'contribute');
      $contactFields = CRM_Contact_BAO_Contact::importableFields($contacType, NULL);

      // Using new Dedupe rule.
      $ruleParams = array(
        'contact_type' => $contacType,
        'level' => 'Strict',
      );
      $fieldsArray = CRM_Dedupe_BAO_Rule::dedupeRuleFields($ruleParams);
      $tmpConatctField = array();
      if (is_array($fieldsArray)) {
        foreach ($fieldsArray as $value) {
          //skip if there is no dupe rule
          if ($value == 'none') {
            continue;
          }
          $customFieldId = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_CustomField',
            $value,
            'id',
            'column_name'
          );
          $value = $customFieldId ? 'custom_' . $customFieldId : $value;
          $tmpConatctField[trim($value)] = $contactFields[trim($value)];
          if (!$status) {
            $title = $tmpConatctField[trim($value)]['title'] . ' ' . ts('(match to contact)');
          }
          else {
            $title = $tmpConatctField[trim($value)]['title'];
          }
          $tmpConatctField[trim($value)]['title'] = $title;
        }
      }

      $tmpConatctField['external_identifier'] = $contactFields['external_identifier'];
      $tmpConatctField['external_identifier']['title'] = $contactFields['external_identifier']['title'] . ' ' . ts('(match to contact)');
      $tmpFields['contribution_contact_id']['title'] = $tmpFields['contribution_contact_id']['title'] . ' ' . ts('(match to contact)');
      $fields = array_merge($fields, $tmpConatctField);
      $fields = array_merge($fields, $tmpFields);
      $fields = array_merge($fields, $note);
      $fields = array_merge($fields, $optionFields);
      $fields = array_merge($fields, CRM_Contribute_DAO_ContributionType::export());
      $fields = array_merge($fields, CRM_Core_BAO_CustomField::getFieldsForImport('Contribution'));
      self::$_importableFields = $fields;
    }
    return self::$_importableFields;
  }

  static function &exportableFields() {
    if (!self::$_exportableFields) {
      if (!self::$_exportableFields) {
        self::$_exportableFields = array();
      }

      $impFields          = CRM_Contribute_DAO_Contribution::export();
      $expFieldProduct    = CRM_Contribute_DAO_Product::export();
      $expFieldsContrib   = CRM_Contribute_DAO_ContributionProduct::export();
      $typeField          = CRM_Contribute_DAO_ContributionType::export();
      $optionField        = CRM_Core_OptionValue::getFields($mode = 'contribute');
      $contributionStatus = array(
        'contribution_status' => array('title' => 'Contribution Status',
          'name' => 'contribution_status',
          'data_type' => CRM_Utils_Type::T_STRING,
        ));

      $contributionNote = array('contribution_note' => array('title' => ts('Contribution Note'),
          'name' => 'contribution_note',
          'data_type' => CRM_Utils_Type::T_TEXT,
        ));

      $contributionRecurId = array('contribution_recur_id' => array('title' => ts('Recurring Contributions ID'),
          'name' => 'contribution_recur_id',
          'where' => 'civicrm_contribution.contribution_recur_id',
          'data_type' => CRM_Utils_Type::T_INT,
        ));

      $extraFields = array(
        'contribution_campaign' => array(
          'title' => ts('Campaign Title')
        ),
        'contribution_batch' => array(
          'title' => ts('Batch Name')
        )
      );

      $fields = array_merge($impFields, $typeField, $contributionStatus, $optionField, $expFieldProduct,
        $expFieldsContrib, $contributionNote, $contributionRecurId, $extraFields,
        CRM_Core_BAO_CustomField::getFieldsForImport('Contribution')
      );

      self::$_exportableFields = $fields;
    }
    return self::$_exportableFields;
  }

  function getTotalAmountAndCount($status = NULL, $startDate = NULL, $endDate = NULL) {

    $where = array();
    switch ($status) {
      case 'Valid':
        $where[] = 'contribution_status_id = 1';
        break;

      case 'Cancelled':
        $where[] = 'contribution_status_id = 3';
        break;
    }

    if ($startDate) {
      $where[] = "receive_date >= '" . CRM_Utils_Type::escape($startDate, 'Timestamp') . "'";
    }
    if ($endDate) {
      $where[] = "receive_date <= '" . CRM_Utils_Type::escape($endDate, 'Timestamp') . "'";
    }

    $whereCond = implode(' AND ', $where);

    $query = "
    SELECT  sum( total_amount ) as total_amount,
            count( civicrm_contribution.id ) as total_count,
            currency
      FROM  civicrm_contribution
INNER JOIN  civicrm_contact contact ON ( contact.id = civicrm_contribution.contact_id )
     WHERE  $whereCond
       AND  ( is_test = 0 OR is_test IS NULL )
       AND  contact.is_deleted = 0
  GROUP BY  currency
";

    $dao    = CRM_Core_DAO::executeQuery($query, CRM_Core_DAO::$_nullArray);
    $amount = array();
    $count  = 0;
    while ($dao->fetch()) {
      $count += $dao->total_count;
      $amount[] = CRM_Utils_Money::format($dao->total_amount, $dao->currency);
    }
    if ($count) {
      return array('amount' => implode(', ', $amount),
        'count' => $count,
      );
    }
    return NULL;
  }

  /**
   * Delete the indirect records associated with this contribution first
   *
   * @return $results no of deleted Contribution on success, false otherwise
   * @access public
   * @static
   */
  static function deleteContribution($id) {
    CRM_Utils_Hook::pre('delete', 'Contribution', $id, CRM_Core_DAO::$_nullArray);

    $transaction = new CRM_Core_Transaction();

    $results = NULL;
    //delete activity record
    $params = array(
      'source_record_id' => $id,
      // activity type id for contribution
      'activity_type_id' => 6,
    );

    CRM_Activity_BAO_Activity::deleteActivity($params);

    //delete billing address if exists for this contribution.
    self::deleteAddress($id);

    //update pledge and pledge payment, CRM-3961
    CRM_Pledge_BAO_PledgePayment::resetPledgePayment($id);

    // remove entry from civicrm_price_set_entity, CRM-5095
    if (CRM_Price_BAO_Set::getFor('civicrm_contribution', $id)) {
      CRM_Price_BAO_Set::removeFrom('civicrm_contribution', $id);
    }
    // cleanup line items.
    $participantId = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_ParticipantPayment', $id, 'participant_id', 'contribution_id');

    // delete any related entity_financial_trxn and financial_trxn records.
    CRM_Core_BAO_FinancialTrxn::deleteFinancialTrxn($id, 'civicrm_contribution');

    if ($participantId) {
      CRM_Price_BAO_LineItem::deleteLineItems($participantId, 'civicrm_participant');
    }
    else {
      CRM_Price_BAO_LineItem::deleteLineItems($id, 'civicrm_contribution');
    }

    //delete note.
    $note = CRM_Core_BAO_Note::getNote($id, 'civicrm_contribution');
    $noteId = key($note);
    if ($noteId) {
      CRM_Core_BAO_Note::del($noteId, FALSE);
    }

    $dao = new CRM_Contribute_DAO_Contribution();
    $dao->id = $id;

    $results = $dao->delete();

    $transaction->commit();

    CRM_Utils_Hook::post('delete', 'Contribution', $dao->id, $dao);

    // delete the recently created Contribution
    $contributionRecent = array(
      'id' => $id,
      'type' => 'Contribution',
    );
    CRM_Utils_Recent::del($contributionRecent);

    return $results;
  }

  /**
   * Check if there is a contribution with the same trxn_id or invoice_id
   *
   * @param array  $params (reference ) an assoc array of name/value pairs
   * @param array  $duplicates (reference ) store ids of duplicate contribs
   *
   * @return boolean true if duplicate, false otherwise
   * @access public
   * static
   */
  static function checkDuplicate($input, &$duplicates, $id = NULL) {
    if (!$id) {
      $id = CRM_Utils_Array::value('id', $input);
    }
    $trxn_id = CRM_Utils_Array::value('trxn_id', $input);
    $invoice_id = CRM_Utils_Array::value('invoice_id', $input);

    $clause = array();
    $input = array();

    if ($trxn_id) {
      $clause[] = "trxn_id = %1";
      $input[1] = array($trxn_id, 'String');
    }

    if ($invoice_id) {
      $clause[] = "invoice_id = %2";
      $input[2] = array($invoice_id, 'String');
    }

    if (empty($clause)) {
      return FALSE;
    }

    $clause = implode(' OR ', $clause);
    if ($id) {
      $clause = "( $clause ) AND id != %3";
      $input[3] = array($id, 'Integer');
    }

    $query  = "SELECT id FROM civicrm_contribution WHERE $clause";
    $dao    = CRM_Core_DAO::executeQuery($query, $input);
    $result = FALSE;
    while ($dao->fetch()) {
      $duplicates[] = $dao->id;
      $result = TRUE;
    }
    return $result;
  }

  /**
   * takes an associative array and creates a contribution_product object
   *
   * the function extract all the params it needs to initialize the create a
   * contribution_product object. the params array could contain additional unused name/value
   * pairs
   *
   * @param array  $params (reference ) an assoc array of name/value pairs
   *
   * @return object CRM_Contribute_BAO_ContributionProduct object
   * @access public
   * @static
   */
  static function addPremium(&$params) {
    $contributionProduct = new CRM_Contribute_DAO_ContributionProduct();
    $contributionProduct->copyValues($params);
    return $contributionProduct->save();
  }

  /**
   * Function to get list of contribution fields for profile
   * For now we only allow custom contribution fields to be in
   * profile
   *
   * @param boolean $addExtraFields true if special fields needs to be added
   *
   * @return return the list of contribution fields
   * @static
   * @access public
   */
  static function getContributionFields($addExtraFields = TRUE) {
    $contributionFields = CRM_Contribute_DAO_Contribution::export();
    $contributionFields = array_merge($contributionFields, CRM_Core_OptionValue::getFields($mode = 'contribute'));

    if ($addExtraFields) {
      $contributionFields = array_merge($contributionFields, self::getSpecialContributionFields());
    }

    $contributionFields = array_merge($contributionFields, CRM_Contribute_DAO_ContributionType::export());

    foreach ($contributionFields as $key => $var) {
      if ($key == 'contribution_contact_id') {
        continue;
      }
      elseif ($key == 'contribution_campaign_id') {
        $var['title'] = ts('Campaign');
      }
      $fields[$key] = $var;
    }

    $fields = array_merge($fields, CRM_Core_BAO_CustomField::getFieldsForImport('Contribution'));
    return $fields;
  }

  /**
   * Function to add extra fields specific to contribtion
   *
   * @static
   */
  static function getSpecialContributionFields() {
    $extraFields = array(
      'honor_contact_name' => array(
        'name' => 'honor_contact_name',
        'title' => 'Honor Contact Name',
        'headerPattern' => '/^honor_contact_name$/i',
        'where' => 'civicrm_contact_c.display_name',
      ),
      'honor_contact_email' => array(
        'name' => 'honor_contact_email',
        'title' => 'Honor Contact Email',
        'headerPattern' => '/^honor_contact_email$/i',
        'where' => 'honor_email.email',
      ),
      'honor_contact_id' => array(
        'name' => 'honor_contact_id',
        'title' => 'Honor Contact ID',
        'headerPattern' => '/^honor_contact_id$/i',
        'where' => 'civicrm_contribution.honor_contact_id',
      ),
      'honor_type_label' => array(
        'name' => 'honor_type_label',
        'title' => 'Honor Type Label',
        'headerPattern' => '/^honor_type_label$/i',
        'where' => 'honor_type.label',
      ),
      'soft_credit_name' => array(
        'name' => 'soft_credit_name',
        'title' => 'Soft Credit Name',
        'headerPattern' => '/^soft_credit_name$/i',
        'where' => 'civicrm_contact_d.display_name',
      ),
      'soft_credit_email' => array(
        'name' => 'soft_credit_email',
        'title' => 'Soft Credit Email',
        'headerPattern' => '/^soft_credit_email$/i',
        'where' => 'soft_email.email',
      ),
      'soft_credit_phone' => array(
        'name' => 'soft_credit_phone',
        'title' => 'Soft Credit Phone',
        'headerPattern' => '/^soft_credit_phone$/i',
        'where' => 'soft_phone.phone',
      ),
      'soft_credit_contact_id' => array(
        'name' => 'soft_credit_contact_id',
        'title' => 'Soft Credit Contact ID',
        'headerPattern' => '/^soft_credit_contact_id$/i',
        'where' => 'civicrm_contribution_soft.contact_id',
      ),
    );

    return $extraFields;
  }

  static function getCurrentandGoalAmount($pageID) {
    $query = "
SELECT p.goal_amount as goal, sum( c.total_amount ) as total
  FROM civicrm_contribution_page p,
       civicrm_contribution      c
 WHERE p.id = c.contribution_page_id
   AND p.id = %1
   AND c.cancel_date is null
GROUP BY p.id
";

    $config = CRM_Core_Config::singleton();
    $params = array(1 => array($pageID, 'Integer'));
    $dao    = CRM_Core_DAO::executeQuery($query, $params);

    if ($dao->fetch()) {
      return array($dao->goal, $dao->total);
    }
    else {
      return array(NULL, NULL);
    }
  }

  /**
   * Function to create is honor of
   *
   * @param array $params  associated array of fields (by reference)
   * @param int   $honorId honor Id
   * @param array $honorParams any params that should be send to the create function
   * @return contact id
   */
  function createHonorContact(&$params, $honorId = NULL, $honorParams = array()) {
    $honorParams = array_merge(
      array(
        'first_name' => $params['honor_first_name'],
        'last_name' => $params['honor_last_name'],
        'prefix_id' => $params['honor_prefix_id'],
        'email-Primary' => $params['honor_email'],
      ),
      $honorParams
    );
    if (!$honorId) {
      $honorParams['email'] = $params['honor_email'];

      $dedupeParams = CRM_Dedupe_Finder::formatParams($honorParams, 'Individual');
      $dedupeParams['check_permission'] = FALSE;
      $ids = CRM_Dedupe_Finder::dupesByParams($dedupeParams, 'Individual');

      // if we find more than one contact, use the first one
      $honorId = CRM_Utils_Array::value(0, $ids);
    }

    $contact = &CRM_Contact_BAO_Contact::createProfileContact($honorParams,
      CRM_Core_DAO::$_nullArray,
      $honorId
    );
    return $contact;
  }

  /**
   * Function to get list of contribution In Honor of contact Ids
   *
   * @param int $honorId In Honor of Contact ID
   *
   * @return return the list of contribution fields
   *
   * @access public
   * @static
   */
  static function getHonorContacts($honorId) {
    $params = array();
    $honorDAO = new CRM_Contribute_DAO_Contribution();
    $honorDAO->honor_contact_id = $honorId;
    $honorDAO->find();

    $status = CRM_Contribute_PseudoConstant::contributionStatus($honorDAO->contribution_status_id);
    $type = CRM_Contribute_PseudoConstant::contributionType();

    while ($honorDAO->fetch()) {
      $params[$honorDAO->id]['honorId'] = $honorDAO->contact_id;
      $params[$honorDAO->id]['display_name'] = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact', $honorDAO->contact_id, 'display_name');
      $params[$honorDAO->id]['type'] = $type[$honorDAO->contribution_type_id];
      $params[$honorDAO->id]['type_id'] = $honorDAO->contribution_type_id;
      $params[$honorDAO->id]['amount'] = CRM_Utils_Money::format($honorDAO->total_amount, $honorDAO->currency);
      $params[$honorDAO->id]['source'] = $honorDAO->source;
      $params[$honorDAO->id]['receive_date'] = $honorDAO->receive_date;
      $params[$honorDAO->id]['contribution_status'] = CRM_Utils_Array::value($honorDAO->contribution_status_id, $status);
    }

    return $params;
  }

  /**
   * function to get the sort name of a contact for a particular contribution
   *
   * @param  int    $id      id of the contribution
   *
   * @return null|string     sort name of the contact if found
   * @static
   * @access public
   */
  static function sortName($id) {
    $id = CRM_Utils_Type::escape($id, 'Integer');

    $query = "
SELECT civicrm_contact.sort_name
FROM   civicrm_contribution, civicrm_contact
WHERE  civicrm_contribution.contact_id = civicrm_contact.id
  AND  civicrm_contribution.id = {$id}
";
    return CRM_Core_DAO::singleValueQuery($query, CRM_Core_DAO::$_nullArray);
  }

  static function annual($contactID) {
    if (is_array($contactID)) {
      $contactIDs = implode(',', $contactID);
    }
    else {
      $contactIDs = $contactID;
    }

    $config = CRM_Core_Config::singleton();
    $startDate = $endDate = NULL;

    $currentMonth = date('m');
    $currentDay = date('d');
    if ((int ) $config->fiscalYearStart['M'] > $currentMonth ||
      ((int ) $config->fiscalYearStart['M'] == $currentMonth &&
        (int ) $config->fiscalYearStart['d'] > $currentDay
      )
    ) {
      $year = date('Y') - 1;
    }
    else {
      $year = date('Y');
    }
    $nextYear = $year + 1;

    if ($config->fiscalYearStart) {
      if ($config->fiscalYearStart['M'] < 10) {
        $config->fiscalYearStart['M'] = '0' . $config->fiscalYearStart['M'];
      }
      if ($config->fiscalYearStart['d'] < 10) {
        $config->fiscalYearStart['d'] = '0' . $config->fiscalYearStart['d'];
      }
      $monthDay = $config->fiscalYearStart['M'] . $config->fiscalYearStart['d'];
    }
    else {
      $monthDay = '0101';
    }
    $startDate = "$year$monthDay";
    $endDate = "$nextYear$monthDay";

    $query = "
SELECT count(*) as count,
       sum(total_amount) as amount,
       avg(total_amount) as average,
       currency
  FROM civicrm_contribution b
 WHERE b.contact_id IN ( $contactIDs )
   AND b.contribution_status_id = 1
   AND b.is_test = 0
   AND b.receive_date >= $startDate
   AND b.receive_date <  $endDate
GROUP BY currency
";
    $dao    = CRM_Core_DAO::executeQuery($query, CRM_Core_DAO::$_nullArray);
    $count  = 0;
    $amount = $average = array();
    while ($dao->fetch()) {
      if ($dao->count > 0 && $dao->amount > 0) {
        $count += $dao->count;
        $amount[] = CRM_Utils_Money::format($dao->amount, $dao->currency);
        $average[] = CRM_Utils_Money::format($dao->average, $dao->currency);
      }
    }
    if ($count > 0) {
      return array(
        $count,
        implode(',&nbsp;', $amount),
        implode(',&nbsp;', $average),
      );
    }
    return array(0, 0, 0);
  }

  /**
   * Check if there is a contribution with the params passed in.
   * Used for trxn_id,invoice_id and contribution_id
   *
   * @param array  $params (reference ) an assoc array of name/value pairs
   *
   * @return array contribution id if success else NULL
   * @access public
   * static
   */
  static function checkDuplicateIds($params) {
    $dao = new CRM_Contribute_DAO_Contribution();

    $clause = array();
    $input = array();
    foreach ($params as $k => $v) {
      if ($v) {
        $clause[] = "$k = '$v'";
      }
    }
    $clause = implode(' AND ', $clause);
    $query  = "SELECT id FROM civicrm_contribution WHERE $clause";
    $dao    = CRM_Core_DAO::executeQuery($query, $input);

    while ($dao->fetch()) {
      $result = $dao->id;
      return $result;
    }
    return NULL;
  }

  /**
   * Function to get the contribution details for component export
   *
   * @param int     $exportMode export mode
   * @param string  $componentIds  component ids
   *
   * @return array associated array
   *
   * @static
   * @access public
   */
  static function getContributionDetails($exportMode, $componentIds) {
    $paymentDetails = array();
    $componentClause = ' IN ( ' . implode(',', $componentIds) . ' ) ';

    if ($exportMode == CRM_Export_Form_Select::EVENT_EXPORT) {
      $componentSelect = " civicrm_participant_payment.participant_id id";
      $additionalClause = "
INNER JOIN civicrm_participant_payment ON (civicrm_contribution.id = civicrm_participant_payment.contribution_id
AND civicrm_participant_payment.participant_id {$componentClause} )
";
    }
    elseif ($exportMode == CRM_Export_Form_Select::MEMBER_EXPORT) {
      $componentSelect = " civicrm_membership_payment.membership_id id";
      $additionalClause = "
INNER JOIN civicrm_membership_payment ON (civicrm_contribution.id = civicrm_membership_payment.contribution_id
AND civicrm_membership_payment.membership_id {$componentClause} )
";
    }
    elseif ($exportMode == CRM_Export_Form_Select::PLEDGE_EXPORT) {
      $componentSelect = " civicrm_pledge_payment.id id";
      $additionalClause = "
INNER JOIN civicrm_pledge_payment ON (civicrm_contribution.id = civicrm_pledge_payment.contribution_id
AND civicrm_pledge_payment.pledge_id {$componentClause} )
";
    }

    $query = " SELECT total_amount, contribution_status.name as status_id, contribution_status.label as status, payment_instrument.name as payment_instrument, receive_date,
                          trxn_id, {$componentSelect}
FROM civicrm_contribution
LEFT JOIN civicrm_option_group option_group_payment_instrument ON ( option_group_payment_instrument.name = 'payment_instrument')
LEFT JOIN civicrm_option_value payment_instrument ON (civicrm_contribution.payment_instrument_id = payment_instrument.value
     AND option_group_payment_instrument.id = payment_instrument.option_group_id )
LEFT JOIN civicrm_option_group option_group_contribution_status ON (option_group_contribution_status.name = 'contribution_status')
LEFT JOIN civicrm_option_value contribution_status ON (civicrm_contribution.contribution_status_id = contribution_status.value
                               AND option_group_contribution_status.id = contribution_status.option_group_id )
{$additionalClause}
";

    $dao = CRM_Core_DAO::executeQuery($query, CRM_Core_DAO::$_nullArray);

    while ($dao->fetch()) {
      $paymentDetails[$dao->id] = array(
        'total_amount' => $dao->total_amount,
        'contribution_status' => $dao->status,
        'receive_date' => $dao->receive_date,
        'pay_instru' => $dao->payment_instrument,
        'trxn_id' => $dao->trxn_id,
      );
    }

    return $paymentDetails;
  }

  /**
   *  Function to create address associated with contribution record.
   *  @param array $params an associated array
   *  @param int   $billingID $billingLocationTypeID
   *
   *  @return address id
   *  @static
   */
  static function createAddress(&$params, $billingLocationTypeID) {
    $billingFields = array(
      'street_address',
      'city',
      'state_province_id',
      'postal_code',
      'country_id',
    );

    //build address array
    $addressParams = array();
    $addressParams['location_type_id'] = $billingLocationTypeID;
    $addressParams['is_billing'] = 1;
    $addressParams['address_name'] = "{$params['billing_first_name']}" . CRM_Core_DAO::VALUE_SEPARATOR . "{$params['billing_middle_name']}" . CRM_Core_DAO::VALUE_SEPARATOR . "{$params['billing_last_name']}";

    foreach ($billingFields as $value) {
      $addressParams[$value] = $params["billing_{$value}-{$billingLocationTypeID}"];
    }

    $address = CRM_Core_BAO_Address::add($addressParams, FALSE);

    return $address->id;
  }

  /**
   *  Function to create soft contributon with contribution record.
   *  @param array $params an associated array
   *
   *  @return soft contribution id
   *  @static
   */
  static function addSoftContribution($params) {
    $softContribution = new CRM_Contribute_DAO_ContributionSoft();
    $softContribution->copyValues($params);

    // set currency for CRM-1496
    if (!isset($softContribution->currency)) {
      $config = CRM_Core_Config::singleton();
      $softContribution->currency = $config->defaultCurrency;
    }

    return $softContribution->save();
  }

  /**
   *  Function to retrieve soft contributon for contribution record.
   *  @param array $params an associated array
   *
   *  @return soft contribution id
   *  @static
   */
  static function getSoftContribution($params, $all = FALSE) {
    $cs = new CRM_Contribute_DAO_ContributionSoft();
    $cs->copyValues($params);
    $softContribution = array();
    if ($cs->find(TRUE)) {
      if ($all) {
        foreach (array(
          'pcp_id', 'pcp_display_in_roll', 'pcp_roll_nickname', 'pcp_personal_note') as $key => $val) {
          $softContribution[$val] = $cs->$val;
        }
      }
      $softContribution['soft_credit_to'] = $cs->contact_id;
      $softContribution['soft_credit_id'] = $cs->id;
    }
    return $softContribution;
  }

  /**
   *  Function to retrieve the list of soft contributons for given contact.
   *  @param int $contact_id contact id
   *
   *  @return array
   *  @static
   */
  static function getSoftContributionList($contact_id, $isTest = 0) {
    $query = "
    SELECT ccs.id, ccs.amount as amount,
           ccs.contribution_id,
           ccs.pcp_id,
           ccs.pcp_display_in_roll,
           ccs.pcp_roll_nickname,
           ccs.pcp_personal_note,
           cc.receive_date,
           cc.contact_id as contributor_id,
           cc.contribution_status_id as contribution_status_id,
           cp.title as pcp_title,
           cc.currency,
           contact.display_name,
           cct.name as contributionType
    FROM civicrm_contribution_soft ccs
         LEFT JOIN civicrm_contribution cc
                ON ccs.contribution_id = cc.id
         LEFT JOIN civicrm_pcp cp
                ON ccs.pcp_id = cp.id
         LEFT JOIN civicrm_contact contact
                ON ccs.contribution_id = cc.id AND
                   cc.contact_id = contact.id
         LEFT JOIN civicrm_contribution_type cct
                ON cc.contribution_type_id = cct.id
         WHERE cc.is_test = %2 AND ccs.contact_id = %1
         ORDER BY cc.receive_date DESC";

    $params             = array(1 => array($contact_id, 'Integer'),
                                2 => array($isTest, 'Integer'));
    $cs                 = CRM_Core_DAO::executeQuery($query, $params);
    $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus();
    $result             = array();
    while ($cs->fetch()) {
      $result[$cs->id]['amount'] = $cs->amount;
      $result[$cs->id]['currency'] = $cs->currency;
      $result[$cs->id]['contributor_id'] = $cs->contributor_id;
      $result[$cs->id]['contribution_id'] = $cs->contribution_id;
      $result[$cs->id]['contributor_name'] = $cs->display_name;
      $result[$cs->id]['contribution_type'] = $cs->contributionType;
      $result[$cs->id]['receive_date'] = $cs->receive_date;
      $result[$cs->id]['pcp_id'] = $cs->pcp_id;
      $result[$cs->id]['pcp_title'] = $cs->pcp_title;
      $result[$cs->id]['pcp_display_in_roll'] = $cs->pcp_display_in_roll;
      $result[$cs->id]['pcp_roll_nickname'] = $cs->pcp_roll_nickname;
      $result[$cs->id]['pcp_personal_note'] = $cs->pcp_personal_note;
      $result[$cs->id]['contribution_status'] = CRM_Utils_Array::value($cs->contribution_status_id, $contributionStatus);

      if ($isTest) {
        $result[$cs->id]['contribution_status'] = $result[$cs->id]['contribution_status'] . '<br /> (test)';
      }
    }
    return $result;
  }

  static function getSoftContributionTotals($contact_id, $isTest = 0) {
    $query = "
    SELECT SUM(amount) as amount,
           AVG(total_amount) as average,
           cc.currency
    FROM civicrm_contribution_soft  ccs
         LEFT JOIN civicrm_contribution cc
                ON ccs.contribution_id = cc.id
    WHERE cc.is_test = %2 AND
          ccs.contact_id = %1
    GROUP BY currency ";

    $params = array(1 => array($contact_id, 'Integer'),
                    2 => array($isTest, 'Integer'));

    $cs = CRM_Core_DAO::executeQuery($query, $params);

    $count = 0;
    $amount = $average = array();

    while ($cs->fetch()) {
      if ($cs->amount > 0) {
        $count++;
        $amount[]   = $cs->amount;
        $average[]  = $cs->average;
        $currency[] = $cs->currency;
      }
    }

    if ($count > 0) {
      return array(implode(',&nbsp;', $amount),
        implode(',&nbsp;', $average),
        implode(',&nbsp;', $currency),
      );
    }
    return array(0, 0);
  }

  /**
   * Delete billing address record related contribution
   *
   * @param int $contact_id contact id
   * @param int $contribution_id contributionId
   * @access public
   * @static
   */
  static function deleteAddress($contributionId = NULL, $contactId = NULL) {
    $clauses = array();
    $contactJoin = NULL;

    if ($contributionId) {
      $clauses[] = "cc.id = {$contributionId}";
    }

    if ($contactId) {
      $clauses[] = "cco.id = {$contactId}";
      $contactJoin = "INNER JOIN civicrm_contact cco ON cc.contact_id = cco.id";
    }

    if (empty($clauses)) {
      CRM_Core_Error::fatal();
    }

    $condition = implode(' OR ', $clauses);

    $query = "
SELECT     ca.id
FROM       civicrm_address ca
INNER JOIN civicrm_contribution cc ON cc.address_id = ca.id
           $contactJoin
WHERE      $condition
";
    $dao = CRM_Core_DAO::executeQuery($query);

    while ($dao->fetch()) {
      $params = array('id' => $dao->id);
      CRM_Core_BAO_Block::blockDelete('Address', $params);
    }
  }

  /**
   * This function check online pending contribution associated w/
   * Online Event Registration or Online Membership signup.
   *
   * @param int    $componentId   participant/membership id.
   * @param string $componentName Event/Membership.
   *
   * @return $contributionId pending contribution id.
   * @static
   */
  static function checkOnlinePendingContribution($componentId, $componentName) {
    $contributionId = NULL;
    if (!$componentId ||
      !in_array($componentName, array('Event', 'Membership'))
    ) {
      return $contributionId;
    }

    if ($componentName == 'Event') {
      $idName         = 'participant_id';
      $componentTable = 'civicrm_participant';
      $paymentTable   = 'civicrm_participant_payment';
      $source         = ts('Online Event Registration');
    }

    if ($componentName == 'Membership') {
      $idName         = 'membership_id';
      $componentTable = 'civicrm_membership';
      $paymentTable   = 'civicrm_membership_payment';
      $source         = ts('Online Contribution');
    }

    $pendingStatusId = array_search('Pending', CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name'));

    $query = "
   SELECT  component.id as {$idName},
           componentPayment.contribution_id as contribution_id,
           contribution.source source,
           contribution.contribution_status_id as contribution_status_id,
           contribution.is_pay_later as is_pay_later
     FROM  $componentTable component
LEFT JOIN  $paymentTable componentPayment    ON ( componentPayment.{$idName} = component.id )
LEFT JOIN  civicrm_contribution contribution ON ( componentPayment.contribution_id = contribution.id )
    WHERE  component.id = {$componentId}";

    $dao = CRM_Core_DAO::executeQuery($query);

    while ($dao->fetch()) {
      if ($dao->contribution_id &&
        $dao->is_pay_later &&
        $dao->contribution_status_id == $pendingStatusId &&
        strpos($dao->source, $source) !== FALSE
      ) {
        $contributionId = $dao->contribution_id;
        $dao->free();
      }
    }

    return $contributionId;
  }

  /**
   * This function update contribution as well as related objects.
   */
  function transitionComponents($params, $processContributionObject = FALSE) {
    // get minimum required values.
    $contactId = CRM_Utils_Array::value('contact_id', $params);
    $componentId = CRM_Utils_Array::value('component_id', $params);
    $componentName = CRM_Utils_Array::value('componentName', $params);
    $contributionId = CRM_Utils_Array::value('contribution_id', $params);
    $contributionStatusId = CRM_Utils_Array::value('contribution_status_id', $params);

    // if we already processed contribution object pass previous status id.
    $previousContriStatusId = CRM_Utils_Array::value('previous_contribution_status_id', $params);

    $updateResult = array();

    $contributionStatuses = CRM_Contribute_PseudoConstant::contributionStatus(NULL, 'name');

    // we process only ( Completed, Cancelled, or Failed ) contributions.
    if (!$contributionId ||
      !in_array($contributionStatusId, array(array_search('Completed', $contributionStatuses),
          array_search('Cancelled', $contributionStatuses),
          array_search('Failed', $contributionStatuses),
        ))
    ) {
      return $updateResult;
    }

    if (!$componentName || !$componentId) {
      // get the related component details.
      $componentDetails = self::getComponentDetails($contributionId);
    }
    else {
      $componentDetails['contact_id'] = $contactId;
      $componentDetails['component'] = $componentName;

      if ($componentName == 'event') {
        $componentDetails['participant'] = $componentId;
      }
      else {
        $componentDetails['membership'] = $componentId;
      }
    }

    if (CRM_Utils_Array::value('contact_id', $componentDetails)) {
      $componentDetails['contact_id'] = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_Contribution',
        $contributionId,
        'contact_id'
      );
    }

    // do check for required ids.
    if (!CRM_Utils_Array::value('membership', $componentDetails) &&
      !CRM_Utils_Array::value('participant', $componentDetails) &&
      !CRM_Utils_Array::value('pledge_payment', $componentDetails) ||
      !CRM_Utils_Array::value('contact_id', $componentDetails)
    ) {
      return $updateResult;
    }

    //now we are ready w/ required ids, start processing.

    $baseIPN = new CRM_Core_Payment_BaseIPN();

    $input = $ids = $objects = array();

    $input['component'] = CRM_Utils_Array::value('component', $componentDetails);
    $ids['contribution'] = $contributionId;
    $ids['contact'] = CRM_Utils_Array::value('contact_id', $componentDetails);
    $ids['membership'] = CRM_Utils_Array::value('membership', $componentDetails);
    $ids['participant'] = CRM_Utils_Array::value('participant', $componentDetails);
    $ids['event'] = CRM_Utils_Array::value('event', $componentDetails);
    $ids['pledge_payment'] = CRM_Utils_Array::value('pledge_payment', $componentDetails);
    $ids['contributionRecur'] = NULL;
    $ids['contributionPage'] = NULL;

    if (!$baseIPN->validateData($input, $ids, $objects, FALSE)) {
      CRM_Core_Error::fatal();
    }

    $memberships   = &$objects['membership'];
    $participant   = &$objects['participant'];
    $pledgePayment = &$objects['pledge_payment'];
    $contribution  = &$objects['contribution'];

    if ($pledgePayment) {
      $pledgePaymentIDs = array();
      foreach ($pledgePayment as $key => $object) {
        $pledgePaymentIDs[] = $object->id;
      }
      $pledgeID = $pledgePayment[0]->pledge_id;
    }


    $membershipStatuses = CRM_Member_PseudoConstant::membershipStatus();

    if ($participant) {
      $participantStatuses = CRM_Event_PseudoConstant::participantStatus();
      $oldStatus = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_Participant',
        $participant->id,
        'status_id'
      );
    }
    // we might want to process contribution object.
    $processContribution = FALSE;
    if ($contributionStatusId == array_search('Cancelled', $contributionStatuses)) {
      if (is_array($memberships)) {
        foreach ($memberships as $membership) {
          if ($membership) {
            $membership->status_id = array_search('Cancelled', $membershipStatuses);
            $membership->save();

            $updateResult['updatedComponents']['CiviMember'] = $membership->status_id;
            if ($processContributionObject) {
              $processContribution = TRUE;
            }
          }
        }
      }

      if ($participant) {
        $updatedStatusId = array_search('Cancelled', $participantStatuses);
        CRM_Event_BAO_Participant::updateParticipantStatus($participant->id, $oldStatus, $updatedStatusId, TRUE);

        $updateResult['updatedComponents']['CiviEvent'] = $updatedStatusId;
        if ($processContributionObject) {
          $processContribution = TRUE;
        }
      }

      if ($pledgePayment) {
        CRM_Pledge_BAO_PledgePayment::updatePledgePaymentStatus($pledgeID, $pledgePaymentIDs, $contributionStatusId);

        $updateResult['updatedComponents']['CiviPledge'] = $contributionStatusId;
        if ($processContributionObject) {
          $processContribution = TRUE;
        }
      }
    }
    elseif ($contributionStatusId == array_search('Failed', $contributionStatuses)) {
      if (is_array($memberships)) {
        foreach ($memberships as $membership) {
          if ($membership) {
            $membership->status_id = array_search('Expired', $membershipStatuses);
            $membership->save();

            $updateResult['updatedComponents']['CiviMember'] = $membership->status_id;
            if ($processContributionObject) {
              $processContribution = TRUE;
            }
          }
        }
      }
      if ($participant) {
        $updatedStatusId = array_search('Cancelled', $participantStatuses);
        CRM_Event_BAO_Participant::updateParticipantStatus($participant->id, $oldStatus, $updatedStatusId, TRUE);

        $updateResult['updatedComponents']['CiviEvent'] = $updatedStatusId;
        if ($processContributionObject) {
          $processContribution = TRUE;
        }
      }

      if ($pledgePayment) {
        CRM_Pledge_BAO_PledgePayment::updatePledgePaymentStatus($pledgeID, $pledgePaymentIDs, $contributionStatusId);

        $updateResult['updatedComponents']['CiviPledge'] = $contributionStatusId;
        if ($processContributionObject) {
          $processContribution = TRUE;
        }
      }
    }
    elseif ($contributionStatusId == array_search('Completed', $contributionStatuses)) {

      // only pending contribution related object processed.
      if ($previousContriStatusId &&
        ($previousContriStatusId != array_search('Pending', $contributionStatuses))
      ) {
        // this is case when we already processed contribution object.
        return $updateResult;
      }
      elseif (!$previousContriStatusId &&
        $contribution->contribution_status_id != array_search('Pending', $contributionStatuses)
      ) {
        // this is case when we are going to process contribution object later.
        return $updateResult;
      }

      if (is_array($memberships)) {
        foreach ($memberships as $membership) {
          if ($membership) {
            $format = '%Y%m%d';

            //CRM-4523
            $currentMembership = CRM_Member_BAO_Membership::getContactMembership($membership->contact_id,
              $membership->membership_type_id,
              $membership->is_test, $membership->id
            );

            // CRM-8141 update the membership type with the value recorded in log when membership created/renewed
            // this picks up membership type changes during renewals
            $sql = "
SELECT    membership_type_id
FROM      civicrm_membership_log
WHERE     membership_id=$membership->id
ORDER BY  id DESC
LIMIT     1;";
            $dao = new CRM_Core_DAO;
            $dao->query($sql);
            if ($dao->fetch()) {
              if (!empty($dao->membership_type_id)) {
                $membership->membership_type_id = $dao->membership_type_id;
                $membership->save();
              }
              // else fall back to using current membership type
            }
            // else fall back to using current membership type
            $dao->free();

            if ($currentMembership) {
              CRM_Member_BAO_Membership::fixMembershipStatusBeforeRenew($currentMembership,
                $changeToday = NULL
              );
              $dates = CRM_Member_BAO_MembershipType::getRenewalDatesForMembershipType($membership->id,
                $changeToday = NULL
              );
              $dates['join_date'] = CRM_Utils_Date::customFormat($currentMembership['join_date'], $format);
            }
            else {
              $dates = CRM_Member_BAO_MembershipType::getDatesForMembershipType($membership->membership_type_id);
            }

            //get the status for membership.
            $calcStatus = CRM_Member_BAO_MembershipStatus::getMembershipStatusByDate($dates['start_date'],
              $dates['end_date'],
              $dates['join_date'],
              'today',
              TRUE
            );

            $formatedParams = array(
              'status_id' => CRM_Utils_Array::value('id', $calcStatus,
                array_search('Current', $membershipStatuses)
              ),
              'join_date' => CRM_Utils_Date::customFormat($dates['join_date'], $format),
              'start_date' => CRM_Utils_Date::customFormat($dates['start_date'], $format),
              'end_date' => CRM_Utils_Date::customFormat($dates['end_date'], $format),
              'reminder_date' => CRM_Utils_Date::customFormat($dates['reminder_date'], $format),
            );

            CRM_Utils_Hook::pre('edit', 'Membership', $membership->id, $formatedParams);

            $membership->copyValues($formatedParams);
            $membership->save();

            //updating the membership log
            $membershipLog = array();
            $membershipLog = $formatedParams;
            $logStartDate  = CRM_Utils_Date::customFormat($dates['log_start_date'], $format);
            $logStartDate  = ($logStartDate) ? CRM_Utils_Date::isoToMysql($logStartDate) : $formatedParams['start_date'];

            $membershipLog['start_date'] = $logStartDate;
            $membershipLog['membership_id'] = $membership->id;
            $membershipLog['modified_id'] = $membership->contact_id;
            $membershipLog['modified_date'] = date('Ymd');
            $membershipLog['membership_type_id'] = $membership->membership_type_id;

            CRM_Member_BAO_MembershipLog::add($membershipLog, CRM_Core_DAO::$_nullArray);

            //update related Memberships.
            CRM_Member_BAO_Membership::updateRelatedMemberships($membership->id, $formatedParams);

            $updateResult['membership_end_date'] = CRM_Utils_Date::customFormat($dates['end_date'],
              '%B %E%f, %Y'
            );
            $updateResult['updatedComponents']['CiviMember'] = $membership->status_id;
            if ($processContributionObject) {
              $processContribution = TRUE;
            }

            CRM_Utils_Hook::post('edit', 'Membership', $membership->id, $membership);
          }
        }
      }

      if ($participant) {
        $updatedStatusId = array_search('Registered', $participantStatuses);
        CRM_Event_BAO_Participant::updateParticipantStatus($participant->id, $oldStatus, $updatedStatusId, TRUE);

        $updateResult['updatedComponents']['CiviEvent'] = $updatedStatusId;
        if ($processContributionObject) {
          $processContribution = TRUE;
        }
      }

      if ($pledgePayment) {
        CRM_Pledge_BAO_PledgePayment::updatePledgePaymentStatus($pledgeID, $pledgePaymentIDs, $contributionStatusId);

        $updateResult['updatedComponents']['CiviPledge'] = $contributionStatusId;
        if ($processContributionObject) {
          $processContribution = TRUE;
        }
      }
    }

    // process contribution object.
    if ($processContribution) {
      $contributionParams = array();
      $fields = array(
        'contact_id', 'total_amount', 'receive_date', 'is_test', 'campaign_id',
        'payment_instrument_id', 'trxn_id', 'invoice_id', 'contribution_type_id',
        'contribution_status_id', 'non_deductible_amount', 'receipt_date', 'check_number',
      );
      foreach ($fields as $field) {
        if (!CRM_Utils_Array::value($field, $params)) {
          continue;
        }
        $contributionParams[$field] = $params[$field];
      }

      $ids = array('contribution' => $contributionId);
      $contribution = CRM_Contribute_BAO_Contribution::create($contributionParams, $ids);
    }

    return $updateResult;
  }

  /**
   * This function return all contribution related object ids.
   *
   */
  function getComponentDetails($contributionId) {
    $componentDetails = $pledgePayment = array();
    if (!$contributionId) {
      return $componentDetails;
    }

    $query = "
SELECT    c.id                 as contribution_id,
          c.contact_id         as contact_id,
          mp.membership_id     as membership_id,
          m.membership_type_id as membership_type_id,
          pp.participant_id    as participant_id,
          p.event_id           as event_id,
          pgp.id               as pledge_payment_id
FROM      civicrm_contribution c
LEFT JOIN civicrm_membership_payment  mp   ON mp.contribution_id = c.id
LEFT JOIN civicrm_participant_payment pp   ON pp.contribution_id = c.id
LEFT JOIN civicrm_participant         p    ON pp.participant_id  = p.id
LEFT JOIN civicrm_membership          m    ON m.id  = mp.membership_id
LEFT JOIN civicrm_pledge_payment      pgp  ON pgp.contribution_id  = c.id
WHERE     c.id = $contributionId";

    $dao = CRM_Core_DAO::executeQuery($query);
    $componentDetails = array();

    while ($dao->fetch()) {
      $componentDetails['component'] = $dao->participant_id ? 'event' : 'contribute';
      $componentDetails['contact_id'] = $dao->contact_id;
      if ($dao->event_id) {
        $componentDetails['event'] = $dao->event_id;
      }
      if ($dao->participant_id) {
        $componentDetails['participant'] = $dao->participant_id;
      }
      if ($dao->membership_id) {
        if (!isset($componentDetails['membership'])) {
          $componentDetails['membership'] = $componentDetails['membership_type'] = array();
        }
        $componentDetails['membership'][] = $dao->membership_id;
        $componentDetails['membership_type'][] = $dao->membership_type_id;
      }
      if ($dao->pledge_payment_id) {
        $pledgePayment[] = $dao->pledge_payment_id;
      }
    }

    if ($pledgePayment) {
      $componentDetails['pledge_payment'] = $pledgePayment;
    }

    return $componentDetails;
  }

  static function contributionCount($contactId, $includeSoftCredit = TRUE, $includeHonoree = TRUE) {
    if (!$contactId) {
      return 0;
    }

    $fromClause = "civicrm_contribution contribution";
    $whereConditions = array("contribution.contact_id = {$contactId}");
    if ($includeSoftCredit) {
      $fromClause .= " LEFT JOIN civicrm_contribution_soft softContribution
                                             ON ( contribution.id = softContribution.contribution_id )";
      $whereConditions[] = " softContribution.contact_id = {$contactId}";
    }
    if ($includeHonoree) {
      $whereConditions[] = " contribution.honor_contact_id = {$contactId}";
    }
    $whereClause = " contribution.is_test = 0 AND ( " . implode(' OR ', $whereConditions) . " )";

    $query = "
   SELECT  count( contribution.id ) count
     FROM  {$fromClause}
    WHERE  {$whereClause}";

    return CRM_Core_DAO::singleValueQuery($query);
  }

  /**
   * Function to get individual id for onbehalf contribution
   *
   * @param  int   $contributionId  contribution id
   * @param  int   $contributorId   contributer id
   *
   * @return array $ids             containing organization id and individual id
   * @access public
   */
  function getOnbehalfIds($contributionId, $contributorId = NULL) {

    $ids = array();

    if (!$contributionId) {
      return $ids;
    }

    // fetch contributor id if null
    if (!$contributorId) {
      $contributorId = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_Contribution',
        $contributionId, 'contact_id'
      );
    }

    $activityTypeIds = CRM_Core_PseudoConstant::activityType(TRUE, FALSE, FALSE, 'name');
    $activityTypeId = array_search('Contribution', $activityTypeIds);

    if ($activityTypeId && $contributorId) {
      $activityQuery = "
SELECT source_contact_id
  FROM civicrm_activity
 WHERE activity_type_id   = %1
   AND source_record_id   = %2";

      $params = array(1 => array($activityTypeId, 'Integer'),
        2 => array($contributionId, 'Integer'),
      );

      $sourceContactId = CRM_Core_DAO::singleValueQuery($activityQuery, $params);

      // for on behalf contribution source is individual and contributor is organization
      if ($sourceContactId && $sourceContactId != $contributorId) {
        $relationshipTypeIds = CRM_Core_PseudoConstant::relationshipType('name');
        // get rel type id for employee of relation
        foreach ($relationshipTypeIds as $id => $typeVals) {
          if ($typeVals['name_a_b'] == 'Employee of') {
            $relationshipTypeId = $id;
            break;
          }
        }

        $rel = new CRM_Contact_DAO_Relationship();
        $rel->relationship_type_id = $relationshipTypeId;
        $rel->contact_id_a = $sourceContactId;
        $rel->contact_id_b = $contributorId;
        if ($rel->find(TRUE)) {
          $ids['individual_id'] = $rel->contact_id_a;
          $ids['organization_id'] = $rel->contact_id_b;
        }
      }
    }

    return $ids;
  }

  function getContributionDates() {
    $config       = CRM_Core_Config::singleton();
    $currentMonth = date('m');
    $currentDay   = date('d');
    if ((int ) $config->fiscalYearStart['M'] > $currentMonth ||
      ((int ) $config->fiscalYearStart['M'] == $currentMonth &&
        (int ) $config->fiscalYearStart['d'] > $currentDay
      )
    ) {
      $year = date('Y') - 1;
    }
    else {
      $year = date('Y');
    }
    $year     = array('Y' => $year);
    $yearDate = $config->fiscalYearStart;
    $yearDate = array_merge($year, $yearDate);
    $yearDate = CRM_Utils_Date::format($yearDate);

    $monthDate = date('Ym') . '01';

    $now = date('Ymd');

    return array(
      'now' => $now,
      'yearDate' => $yearDate,
      'monthDate' => $monthDate,
    );
  }

  /*
   * Load objects relations to contribution object
   * Objects are stored in the $_relatedObjects property
   * In the first instance we are just moving functionality from BASEIpn -
   * see http://issues.civicrm.org/jira/browse/CRM-9996
   *
   * @param array $input Input as delivered from Payment Processor
   * @param array $ids Ids as Loaded by Payment Processor
   * @param boolean $required Is Payment processor / contribution page required
   * @param boolean $loadAll - load all related objects - even where id not passed in? (allows API to call this)
   * Note that the unit test for the BaseIPN class tests this function
   */
  function loadRelatedObjects(&$input, &$ids, $required = FALSE, $loadAll = false) {
    if($loadAll){
      $ids = array_merge($this->getComponentDetails($this->id),$ids);
      if(empty($ids['contact']) && isset($this->contact_id)){
        $ids['contact'] = $this->contact_id;
      }
    }
    if (empty($this->_component)) {
      if (! empty($ids['event'])) {
        $this->_component = 'event';
      }
      else {
        $this->_component = strtolower(CRM_Utils_Array::value('component', $input, 'contribute'));
      }
    }
    $paymentProcessorID = CRM_Utils_Array::value('paymentProcessor', $ids);
    $contributionType = new CRM_Contribute_BAO_ContributionType();
    $contributionType->id = $this->contribution_type_id;
    if (!$contributionType->find(TRUE)) {
      throw new Exception("Could not find contribution type record: " . $this->contribution_type_id);
    }
    if (!empty($ids['contact'])) {
      $this->_relatedObjects['contact'] = new CRM_Contact_BAO_Contact();
      $this->_relatedObjects['contact']->id = $ids['contact'];
      $this->_relatedObjects['contact']->find(TRUE);
    }
    $this->_relatedObjects['contributionType'] = $contributionType;
    if ($this->_component == 'contribute') {

      // retrieve the other optional objects first so
      // stuff down the line can use this info and do things
      // CRM-6056
      if (!empty($ids['membership'])) {
        if (is_numeric($ids['membership'])) {
          // see if there are any other memberships to be considered for same contribution.
          $query = "
SELECT membership_id
FROM   civicrm_membership_payment
WHERE  contribution_id = %1 AND membership_id != %2";
          $dao = CRM_Core_DAO::executeQuery($query,
            array(1 => array($this->id, 'Integer'),
              2 => array($ids['membership'], 'Integer'),
            )
          );

          $ids['membership'] = array($ids['membership']);
          while ($dao->fetch()) {
            $ids['membership'][] = $dao->membership_id;
          }
        }

        if (is_array($ids['membership'])) {
          foreach ($ids['membership'] as $id) {
            if (!empty($id)) {
              $membership = new CRM_Member_BAO_Membership();
              $membership->id = $id;
              if (!$membership->find(TRUE)) {
                throw new Exception("Could not find membership record: $id");
              }
              $membership->join_date = CRM_Utils_Date::isoToMysql($membership->join_date);
              $membership->start_date = CRM_Utils_Date::isoToMysql($membership->start_date);
              $membership->end_date = CRM_Utils_Date::isoToMysql($membership->end_date);
              $membership->reminder_date = CRM_Utils_Date::isoToMysql($membership->reminder_date);

              $this->_relatedObjects['membership'][] = $membership;
              $membership->free();
            }
          }
        }
      }

      if (!empty($ids['pledge_payment'])) {

        foreach ($ids['pledge_payment'] as $key => $paymentID) {
          if (empty($paymentID)) {
            continue;
          }
          $payment = new CRM_Pledge_BAO_PledgePayment();
          $payment->id = $paymentID;
          if (!$payment->find(TRUE)) {
            throw new Exception("Could not find pledge payment record: " . $paymentID);
          }
          $this->_relatedObjects['pledge_payment'][] = $payment;
        }
      }

      if (!empty($ids['contributionRecur'])) {
        $recur = new CRM_Contribute_BAO_ContributionRecur();
        $recur->id = $ids['contributionRecur'];
        if (!$recur->find(TRUE)) {
          throw new Exception("Could not find recur record: " . $ids['contributionRecur']);
        }
        $this->_relatedObjects['contributionRecur'] = &$recur;
        //get payment processor id from recur object.
        $paymentProcessorID = $recur->payment_processor_id;
      }
      //for normal contribution get the payment processor id.
      if (!$paymentProcessorID) {
        if ($this->contribution_page_id) {
          // get the payment processor id from contribution page
          $paymentProcessorID = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_ContributionPage',
            $this->contribution_page_id,
            'payment_processor'
          );
        }
        //fail to load payment processor id.
        elseif (!CRM_Utils_Array::value('pledge_payment', $ids)) {
          $loadObjectSuccess = TRUE;
          if ($required) {
            throw new Exception("Could not find contribution page for contribution record: " . $this->id);
          }
          return $loadObjectSuccess;
        }
      }
    }
    else {
      // we are in event mode
      // make sure event exists and is valid
      $event = new CRM_Event_BAO_Event();
      $event->id = $ids['event'];
      if ($ids['event'] &&
        !$event->find(TRUE)
      ) {
        throw new Exception("Could not find event: " . $ids['event']);
      }

      $this->_relatedObjects['event'] = &$event;

      $participant = new CRM_Event_BAO_Participant();
      $participant->id = $ids['participant'];
      if ($ids['participant'] &&
        !$participant->find(TRUE)
      ) {
        throw new Exception("Could not find participant: " . $ids['participant']);
      }
      $participant->register_date = CRM_Utils_Date::isoToMysql($participant->register_date);

      $this->_relatedObjects['participant'] = &$participant;

      if (!$paymentProcessorID) {
        $paymentProcessorID = $this->_relatedObjects['event']->payment_processor;
      }
    }

    $loadObjectSuccess = TRUE;
    if ($paymentProcessorID) {
      $paymentProcessor = CRM_Core_BAO_PaymentProcessor::getPayment($paymentProcessorID,
        $this->is_test ? 'test' : 'live'
      );
      $ids['paymentProcessor'] = $paymentProcessorID;
      $this->_relatedObjects['paymentProcessor'] = &$paymentProcessor;
    }
    elseif ($required) {
      $loadObjectSuccess = FALSE;
      throw new Exception("Could not find payment processor for contribution record: " . $this->id);
    }

    return $loadObjectSuccess;
  }

  /*
     * Create array of message information - ie. return html version, txt version, to field
     *
     * @param array $input incoming information
     *  - is_recur - should this be treated as recurring (not sure why you wouldn't
     *    just check presence of recur object but maintaining legacy approach
     *    to be careful)
     * @param array $ids IDs of related objects
     * @param array $values any values that may have already been compiled by calling process
     *   This is augmented by values 'gathered' by gatherMessageValues
     * @param bool $returnMessageText distinguishes between whether to send message or return
     *   message text. We are working towards this function ALWAYS returning message text & calling
     *   function doing emails / pdfs with it
     * @return array $messageArray - messages
     */
  function composeMessageArray(&$input, &$ids, &$values, $recur = FALSE, $returnMessageText = TRUE) {
    if (empty($this->_relatedObjects)) {
      $this->loadRelatedObjects($input, $ids);
    }
    if (empty($this->_component)) {
      $this->_component = CRM_Utils_Array::value('component', $input);
    }

    //not really sure what params might be passed in but lets merge em into values
    $values = array_merge($this->_gatherMessageValues($input, $values, $ids), $values);
    $template = CRM_Core_Smarty::singleton();
    $this->_assignMessageVariablesToTemplate($values, $input, $template, $recur, $returnMessageText);
    //what does recur 'mean here - to do with payment processor return functionality but
    // what is the importance
    if ($recur && !empty($this->_relatedObjects['paymentProcessor'])) {
      $paymentObject = &CRM_Core_Payment::singleton(
        $this->is_test ? 'test' : 'live',
        $this->_relatedObjects['paymentProcessor']
      );

      $entityID = $entity = NULL;
      if (isset($ids['contribution'])) {
        $entity = 'contribution';
        $entityID = $ids['contribution'];
      }
      if (isset($ids['membership']) && $ids['membership']) {
        $entity = 'membership';
        $entityID = $ids['membership'];
      }

      $url = $paymentObject->subscriptionURL($entityID, $entity);
      $template->assign('cancelSubscriptionUrl', $url);

      $url = $paymentObject->subscriptionURL($entityID, $entity, 'billing');
      $template->assign('updateSubscriptionBillingUrl', $url);

      $url = $paymentObject->subscriptionURL($entityID, $entity, 'update');
      $template->assign('updateSubscriptionUrl', $url);

      if ($this->_relatedObjects['paymentProcessor']['billing_mode'] & CRM_Core_Payment::BILLING_MODE_FORM) {
        //direct mode showing billing block, so use directIPN for temporary
        $template->assign('contributeMode', 'directIPN');
      }
    }
    // todo remove strtolower - check consistency
    if (strtolower($this->_component) == 'event') {
      return CRM_Event_BAO_Event::sendMail($ids['contact'], $values,
        $this->_relatedObjects['participant']->id, $this->is_test, $returnMessageText
      );
    }
    else {
      $values['contribution_id'] = $this->id;
      if (CRM_Utils_Array::value('related_contact', $ids)) {
        $values['related_contact'] = $ids['related_contact'];
        if (isset($ids['onbehalf_dupe_alert'])) {
          $values['onbehalf_dupe_alert'] = $ids['onbehalf_dupe_alert'];
        }
        $entityBlock = array(
          'contact_id' => $ids['contact'],
          'location_type_id' => CRM_Core_DAO::getFieldValue('CRM_Core_DAO_LocationType',
            'Home', 'id', 'name'
          ),
        );
        $address = CRM_Core_BAO_Address::getValues($entityBlock);
        $template->assign('onBehalfAddress', $address[$entityBlock['location_type_id']]['display']);
      }
      $isTest = FALSE;
      if ($this->is_test) {
        $isTest = TRUE;
      }
      if (!empty($this->_relatedObjects['membership'])) {
        foreach ($this->_relatedObjects['membership'] as $membership) {
          if ($membership->id) {
            $values['membership_id'] = $membership->id;

            // need to set the membership values here
            $template->assign('membership_assign', 1);
            $template->assign('membership_name',
              CRM_Member_PseudoConstant::membershipType($membership->membership_type_id)
            );
            $template->assign('mem_start_date', $membership->start_date);
            $template->assign('mem_join_date', $membership->join_date);
            $template->assign('mem_end_date', $membership->end_date);
            $membership_status = CRM_Member_PseudoConstant::membershipStatus($membership->status_id, NULL, 'label');
            $template->assign('mem_status', $membership_status);
            if ($membership_status == 'Pending' && $membership->is_pay_later == 1) {
              $template->assign('is_pay_later', 1);
            }

            // if separate payment there are two contributions recorded and the
            // admin will need to send a receipt for each of them separately.
            // we dont link the two in the db (but can potentially infer it if needed)
            $template->assign('is_separate_payment', 0);

            if ($recur && $paymentObject) {
              $url = $paymentObject->subscriptionURL($membership->id, 'membership');
              $template->assign('cancelSubscriptionUrl', $url);
              $url = $paymentObject->subscriptionURL($membership->id, 'membership', 'billing');
              $template->assign('updateSubscriptionBillingUrl', $url);
              $url = $paymentObject->subscriptionURL($entityID, $entity, 'update');
              $template->assign('updateSubscriptionUrl', $url);
            }

            $result = CRM_Contribute_BAO_ContributionPage::sendMail($ids['contact'], $values, $isTest, $returnMessageText);

            return $result;
            // otherwise if its about sending emails, continue sending without return, as we
            // don't want to exit the loop.
          }
        }
      }
      else {
        return CRM_Contribute_BAO_ContributionPage::sendMail($ids['contact'], $values, $isTest, $returnMessageText);
      }
    }
  }
  /*
     * Gather values for contribution mail - this function has been created
     * as part of CRM-9996 refactoring as a step towards simplifying the composeMessage function
     * Values related to the contribution in question are gathered
     *
     * @param array $input input into function (probably from payment processor)
     * @param array $ids   the set of ids related to the inpurt
     *
     * @return array $values
     *
     * NB don't add direct calls to the function as we intend to change the signature
     */
  function _gatherMessageValues($input, &$values, $ids = array()) {
    // set display address of contributor
    if ($this->address_id) {
      $addressParams     = array('id' => $this->address_id);
      $addressDetails    = CRM_Core_BAO_Address::getValues($addressParams, FALSE, 'id');
      $addressDetails    = array_values($addressDetails);
      $values['address'] = $addressDetails[0]['display'];
    }
    if ($this->_component == 'contribute') {
      if (isset($this->contribution_page_id)) {
        CRM_Contribute_BAO_ContributionPage::setValues(
          $this->contribution_page_id,
          $values
        );
        if ($this->contribution_page_id) {
          // CRM-8254 - override default currency if applicable
          $config = CRM_Core_Config::singleton();
          $config->defaultCurrency = CRM_Utils_Array::value(
            'currency',
            $values,
            $config->defaultCurrency
          );
        }
      }
      // no contribution page -probably back office
      else {
        // Handle re-print receipt for offline contributions (call from PDF.php - no contribution_page_id)
        $values['is_email_receipt'] = 1;
        $values['title'] = 'Contribution';
      }
      // set lineItem for contribution
      if ($this->id) {
        $lineItem = CRM_Price_BAO_LineItem::getLineItems($this->id, 'contribution', 1);
        if (!empty($lineItem)) {
          $itemId                = key($lineItems);
          $values['lineItem'][0] = $lineItem;
          $values['priceSetID']  = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_Field', $lineItem[$itemId]['price_field_id'], 'price_set_id');
        }
      }
      $relatedContact = CRM_Contribute_BAO_Contribution::getOnbehalfIds(
        $this->id,
        $this->contact_id
      );
      // if this is onbehalf of contribution then set related contact
      if (CRM_Utils_Array::value('individual_id', $relatedContact)) {
        $values['related_contact'] = $ids['related_contact'] = $relatedContact['individual_id'];
      }
    }
    else {
      // event
      $eventParams = array(
        'id' => $this->_relatedObjects['event']->id,
      );
      $values['event'] = array();

      CRM_Event_BAO_Event::retrieve($eventParams, $values['event']);

      //get location details
      $locationParams = array(
        'entity_id' => $this->_relatedObjects['event']->id,
        'entity_table' => 'civicrm_event',
      );
      $values['location'] = CRM_Core_BAO_Location::getValues($locationParams);

      $ufJoinParams = array(
        'entity_table' => 'civicrm_event',
        'entity_id'    => $ids['event'],
        'module'       => 'CiviEvent',
      );

      list($custom_pre_id,
           $custom_post_ids
           ) = CRM_Core_BAO_UFJoin::getUFGroupIds($ufJoinParams);

      $values['custom_pre_id'] = $custom_pre_id;
      $values['custom_post_id'] = $custom_post_ids;

      // set lineItem for event contribution
      if ($this->id) {
        $participantIds = CRM_Event_BAO_Participant::getParticipantIds($this->id);
        if (!empty($participantIds)) {
          foreach ($participantIds as $pIDs) {
            $lineItem = CRM_Price_BAO_LineItem::getLineItems($pIDs);
            if (!CRM_Utils_System::isNull($lineItem)) {
              $values['lineItem'][] = $lineItem;
            }
          }
        }
      }
    }

    return $values;
  }

  /**
   * Apply variables for message to smarty template - this function is part of analysing what is in the huge
   * function & breaking it down into manageable chunks. Eventually it will be refactored into something else
   * Note we send directly from this function in some cases because it is only partly refactored
   * Don't call this function directly as the signature will change
   */
  function _assignMessageVariablesToTemplate(&$values, $input, &$template, $recur = FALSE, $returnMessageText = True) {
    $template->assign('first_name', $this->_relatedObjects['contact']->first_name);
    $template->assign('last_name', $this->_relatedObjects['contact']->last_name);
    $template->assign('displayName', $this->_relatedObjects['contact']->display_name);
    //assign honor infomation to receiptmessage
    $honorID = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_Contribution',
      $this->id,
      'honor_contact_id'
    );
    if (!empty($honorID)) {

      $honorDefault = $honorIds = array();
      $honorIds['contribution'] = $this->id;
      $idParams = array('id' => $honorID, 'contact_id' => $honorID);
      CRM_Contact_BAO_Contact::retrieve($idParams, $honorDefault, $honorIds);
      $honorType = CRM_Core_PseudoConstant::honor();

      $template->assign('honor_block_is_active', 1);
      if (CRM_Utils_Array::value('prefix_id', $honorDefault)) {
        $prefix = CRM_Core_PseudoConstant::individualPrefix();
        $template->assign('honor_prefix', $prefix[$honorDefault['prefix_id']]);
      }
      $template->assign('honor_first_name', CRM_Utils_Array::value('first_name', $honorDefault));
      $template->assign('honor_last_name', CRM_Utils_Array::value('last_name', $honorDefault));
      $template->assign('honor_email', CRM_Utils_Array::value('email', $honorDefault['email'][1]));
      $template->assign('honor_type', $honorType[$this->honor_type_id]);
    }

    $dao = new CRM_Contribute_DAO_ContributionProduct();
    $dao->contribution_id = $this->id;
    if ($dao->find(TRUE)) {
      $premiumId = $dao->product_id;
      $template->assign('option', $dao->product_option);

      $productDAO = new CRM_Contribute_DAO_Product();
      $productDAO->id = $premiumId;
      $productDAO->find(TRUE);
      $template->assign('selectPremium', TRUE);
      $template->assign('product_name', $productDAO->name);
      $template->assign('price', $productDAO->price);
      $template->assign('sku', $productDAO->sku);
    }
    // add the new contribution values
    if (strtolower($this->_component) == 'contribute') {
      $template->assign('title', CRM_Utils_Array::value('title',$values));
      $amount = CRM_Utils_Array::value('total_amount', $input,(CRM_Utils_Array::value('amount', $input)),null);
      if(empty($amount) && isset($this->total_amount)){
        $amount = $this->total_amount;
      }
      $template->assign('amount', $amount);
      //PCP Info
      $softDAO = new CRM_Contribute_DAO_ContributionSoft();
      $softDAO->contribution_id = $this->id;
      if ($softDAO->find(TRUE)) {
        $template->assign('pcpBlock', TRUE);
        $template->assign('pcp_display_in_roll', $softDAO->pcp_display_in_roll);
        $template->assign('pcp_roll_nickname', $softDAO->pcp_roll_nickname);
        $template->assign('pcp_personal_note', $softDAO->pcp_personal_note);

        //assign the pcp page title for email subject
        $pcpDAO = new CRM_PCP_DAO_PCP();
        $pcpDAO->id = $softDAO->pcp_id;
        if ($pcpDAO->find(TRUE)) {
          $template->assign('title', $pcpDAO->title);
        }
      }
    }
    else {
      $template->assign('title', $values['event']['title']);
      $template->assign('totalAmount', $input['amount']);
    }

    if ($this->contribution_type_id) {
      $values['contribution_type_id'] = $this->contribution_type_id;
    }


    $template->assign('trxn_id', $this->trxn_id);
    $template->assign('receive_date',
      CRM_Utils_Date::mysqlToIso($this->receive_date)
    );
    $template->assign('contributeMode', 'notify');
    $template->assign('action', $this->is_test ? 1024 : 1);
    $template->assign('receipt_text',
      CRM_Utils_Array::value('receipt_text',
        $values
      )
    );
    $template->assign('is_monetary', 1);
    $template->assign('is_recur', (bool) $recur);
    $template->assign('currency', $this->currency);
    $template->assign('address', CRM_Utils_Address::format($input));
    if ($this->_component == 'event') {
      $participantRoles = CRM_Event_PseudoConstant::participantRole();
      $viewRoles = array();
      foreach (explode(CRM_Core_DAO::VALUE_SEPARATOR, $this->_relatedObjects['participant']->role_id) as $k => $v) {
        $viewRoles[] = $participantRoles[$v];
      }
      $values['event']['participant_role'] = implode(', ', $viewRoles);
      $template->assign('event', $values['event']);
      $template->assign('location', $values['location']);
      $template->assign('customPre', $values['custom_pre_id']);
      $template->assign('customPost', $values['custom_post_id']);

      $isTest = FALSE;
      if ($this->_relatedObjects['participant']->is_test) {
        $isTest = TRUE;
      }

      $values['params'] = array();
      //to get email of primary participant.
      $primaryEmail = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Email', $this->_relatedObjects['participant']->contact_id, 'email', 'contact_id');
      $primaryAmount[] = array('label' => $this->_relatedObjects['participant']->fee_level . ' - ' . $primaryEmail, 'amount' => $this->_relatedObjects['participant']->fee_amount);
      //build an array of cId/pId of participants
      $additionalIDs = CRM_Event_BAO_Event::buildCustomProfile($this->_relatedObjects['participant']->id, NULL, $this->_relatedObjects['contact']->id, $isTest, TRUE);
      unset($additionalIDs[$this->_relatedObjects['participant']->id]);
      //send receipt to additional participant if exists
      if (count($additionalIDs)) {
        $template->assign('isPrimary', 0);
        $template->assign('customProfile', NULL);
        //set additionalParticipant true
        $values['params']['additionalParticipant'] = TRUE;
        foreach ($additionalIDs as $pId => $cId) {
          $amount = array();
          //to change the status pending to completed
          $additional = new CRM_Event_DAO_Participant();
          $additional->id = $pId;
          $additional->contact_id = $cId;
          $additional->find(TRUE);
          $additional->register_date = $this->_relatedObjects['participant']->register_date;
          $additional->status_id = 1;
          $additionalParticipantInfo = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Email', $additional->contact_id, 'email', 'contact_id');
          //if additional participant dont have email
          //use display name.
          if (!$additionalParticipantInfo) {
            $additionalParticipantInfo = CRM_Contact_BAO_Contact::displayName($additional->contact_id);
          }
          $amount[0] = array('label' => $additional->fee_level, 'amount' => $additional->fee_amount);
          $primaryAmount[] = array('label' => $additional->fee_level . ' - ' . $additionalParticipantInfo, 'amount' => $additional->fee_amount);
          $additional->save();
          $additional->free();
          $template->assign('amount', $amount);
          CRM_Event_BAO_Event::sendMail($cId, $values, $pId, $isTest, $returnMessageText);
        }
      }

      //build an array of custom profile and assigning it to template
      $customProfile = CRM_Event_BAO_Event::buildCustomProfile($this->_relatedObjects['participant']->id, $values, NULL, $isTest);

      if (count($customProfile)) {
        $template->assign('customProfile', $customProfile);
      }

      // for primary contact
      $values['params']['additionalParticipant'] = FALSE;
      $template->assign('isPrimary', 1);
      $template->assign('amount', $primaryAmount);
      $template->assign('register_date', CRM_Utils_Date::isoToMysql($this->_relatedObjects['participant']->register_date));
      if ($this->payment_instrument_id) {
        $paymentInstrument = CRM_Contribute_PseudoConstant::paymentInstrument();
        $template->assign('paidBy', $paymentInstrument[$this->payment_instrument_id]);
      }
      // carry paylater, since we did not created billing,
      // so need to pull email from primary location, CRM-4395
      $values['params']['is_pay_later'] = $this->_relatedObjects['participant']->is_pay_later;
    }
    return $template;
  }

  /**
   * Function to check whether payment processor supports
   * cancellation of contribution subscription
   *
   * @param int $contributionId contribution id
   *
   * @return boolean
   * @access public
   * @static
   */
  static function isCancelSubscriptionSupported($contributionId, $isNotCancelled = TRUE) {
    $cacheKeyString = "$contributionId";
    $cacheKeyString .= $isNotCancelled ? '_1' : '_0';

    static $supportsCancel = array();

    if (!array_key_exists($cacheKeyString, $supportsCancel)) {
      $supportsCancel[$cacheKeyString] = FALSE;
      $isCancelled = FALSE;

      if ($isNotCancelled) {
        $isCancelled = self::isSubscriptionCancelled($contributionId);
      }

      $paymentObject = CRM_Core_BAO_PaymentProcessor::getProcessorForEntity($contributionId, 'contribute', 'obj');
      if (!empty($paymentObject)) {
        $supportsCancel[$cacheKeyString] = $paymentObject->isSupported('cancelSubscription') && !$isCancelled;
      }
    }
    return $supportsCancel[$cacheKeyString];
  }

  /**
   * Function to check whether subscription is already cancelled
   *
   * @param int $contributionId contribution id
   *
   * @return string $status contribution status
   * @access public
   * @static
   */
  static function isSubscriptionCancelled($contributionId) {
    $sql = "
   SELECT cr.contribution_status_id
     FROM civicrm_contribution_recur cr
LEFT JOIN civicrm_contribution con ON ( cr.id = con.contribution_recur_id )
    WHERE con.id = %1 LIMIT 1";
    $params   = array(1 => array($contributionId, 'Integer'));
    $statusId = CRM_Core_DAO::singleValueQuery($sql, $params);
    $status   = CRM_Contribute_PseudoConstant::contributionStatus($statusId);
    if ($status == 'Cancelled') {
      return TRUE;
    }
    return FALSE;
  }
}

