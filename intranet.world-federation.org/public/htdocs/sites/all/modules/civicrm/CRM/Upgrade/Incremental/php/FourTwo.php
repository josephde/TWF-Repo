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
 | Version 3, 19 November 2007.                                       |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License along with this program; if not, contact CiviCRM LLC       |
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
class CRM_Upgrade_Incremental_php_FourTwo {
  const BATCH_SIZE = 5000;
  const SETTINGS_SNIPPET_PATTERN = '/CRM_Core_ClassLoader::singleton\(\)-\>register/';
  const SETTINGS_SNIPPET = "\nrequire_once 'CRM/Core/ClassLoader.php';\nCRM_Core_ClassLoader::singleton()->register();\n";
  static $_deleteBadDatas = array();

  function verifyPreDBstate(&$errors) {
    return TRUE;
  }
  
  /**
   * Compute any messages which should be displayed before upgrade
   *
   * Note: This function is called iteratively for each upcoming
   * revision to the database.
   *
   * @param $postUpgradeMessage string, alterable
   * @param $rev string, a version number, e.g. '4.2.alpha1', '4.2.beta3', '4.2.0'
   * @return void
   */
  function setPreUpgradeMessage(&$preUpgradeMessage, $rev) {
    if ($rev == '4.2.alpha1') {
      $tables = array('civicrm_contribution_page','civicrm_event','civicrm_group','civicrm_contact');
      if (!CRM_Core_DAO::schemaRequiresRebuilding($tables)){
        $errors = ts("The upgrade has identified some schema integrity issues in the database. It seems some of your constraints are missing. You will have to rebuild your schema before re-trying the upgrade. Please refer ".CRM_Utils_System::docURL2("Ensuring Schema Integrity on Upgrades", FALSE, "Ensuring Schema Integrity on Upgrades", NULL, NULL, "wiki"));
        CRM_Core_Error::fatal($errors);
        return FALSE;
      }
      
      // CRM-10613 delete bad data for membership
      self::deleteBadData();
      if (!empty(self::$_deleteBadDatas)) {
        $deletedMembership = $deletedPayments = $retainedMembership = "";
        foreach (self::$_deleteBadDatas as $badData) {
          $retainedMembership .= "<tr><td>{$badData['contribution']}</td><td>" . array_pop($badData['memberships']) . "</td><tr>";
          foreach ($badData['memberships'] as $value ) {
            $deletedMembership .= "<li>{$value}</li>";
            $deletedPayments .= "<tr><td>{$badData['contribution']}</td><td>" . $value . "</td><tr>"; 
          }
        }
        $preUpgradeMessage .= "<br /><strong>" . ts('The upgrade from CiviCRM version 4.1 to version 4.2 has identified some data integrity issues in the database. If you continue, it will attempt to solve a problem of multiple memberships or multiple payments for a membership associated with a single contribution by deleting the following records:') . "</strong>";

        $preUpgradeMessage .= "<br /><strong>" . ts('For contribution ID ##, membership ID ## will be retained') . "</strong>"; 
        $preUpgradeMessage .= "<table><tr><th>contribution ID</th><th>membership ID</th></tr>" . $retainedMembership . "</table>";
        $preUpgradeMessage .= "<strong>" . ts('and the following memberships will be deleted:') . "</strong><ul>" . $deletedMembership . "</ul>";
        $preUpgradeMessage .= "<strong>" . ts('In addition, the following links between this contribution and memberships will be deleted:') . "</strong>";
        $preUpgradeMessage .= "<table><tr><th>contribution ID</th><th>membership ID</th></tr>" . $deletedPayments . "</table>";
      }
    }

    if ($rev == '4.2.beta2') {  
      // note: error conditions are also checked in upgrade_4_2_beta2()
      if (!defined('CIVICRM_SETTINGS_PATH')) {
        $preUpgradeMessage .= '<br />' . ts('Could not determine path to civicrm.settings.php. Please manually locate it and add these lines at the bottom: <pre>%1</pre>', array(
          1 => self::SETTINGS_SNIPPET,
        ));
      } elseif (preg_match(self::SETTINGS_SNIPPET_PATTERN, file_get_contents(CIVICRM_SETTINGS_PATH))) {
        // OK, nothing to do
      } elseif (!is_writable(CIVICRM_SETTINGS_PATH)) {
        $preUpgradeMessage .= '<br />' . ts('The settings file (%1) must be updated. Please make it writable or manually add these lines:<pre>%2</pre>', array(
          1 => CIVICRM_SETTINGS_PATH,
          2 => self::SETTINGS_SNIPPET,
        ));
      }
    }
  }

  /**
   * Compute any messages which should be displayed after upgrade
   *
   * @param $postUpgradeMessage string, alterable
   * @param $rev string, an intermediate version; note that setPostUpgradeMessage is called repeatedly with different $revs
   * @return void
   */
  function setPostUpgradeMessage(&$postUpgradeMessage, $rev) {
    if ($rev == '4.2.alpha1') {
      // CRM-10613 delete bad data for membership
      self::deleteBadData();
      if (!empty(self::$_deleteBadDatas)) {
        $postUpgradeMessage .= "<br /><strong>" . ts('The following links between this contribution and memberships are successfully deleted:') . "</strong>";
        $postUpgradeMessage .= "<table><tr><th>contribution ID</th><th>membership ID</th></tr>";
        foreach (self::$_deleteBadDatas as $badData) {
          array_pop($badData['memberships']);
          foreach ($badData['memberships'] as $value ) {
            $postUpgradeMessage .= "<tr><td>{$badData['contribution']}</td><td>" . $value . "</td><tr>"; 
          }
        }
        $postUpgradeMessage .= "</table>";
      }
      $postUpgradeMessage .= '<br />' . ts('Default versions of the following System Workflow Message Templates have been modified to handle new functionality: <ul><li>Events - Registration Confirmation and Receipt (on-line)</li><li>Pledges - Acknowledgement</li><li>Pledges - Payment Reminder</li></ul>. If you have modified these templates, please review the new default versions and implement updates as needed to your copies (Administer > Communications > Message Templates > System Workflow Messages).');
    }
    if ($rev == '4.2.beta5') {
      $config = CRM_Core_Config::singleton();
      if (!empty($config->extensionsDir)) {
        $postUpgradeMessage .= '<br />' . ts('Please <a href="%1" target="_blank">configure the Extension Resource URL</a>.', array(
          1 => CRM_Utils_system::url('civicrm/admin/setting/url', 'reset=1')
        ));
      }
    }
  }

  function upgrade_4_2_alpha1($rev) {
    //checking whether the foreign key exists before dropping it
    //drop foreign key queries of CRM-9850
    $params = array();
    $tables = array('civicrm_contribution_page' =>'FK_civicrm_contribution_page_payment_processor_id',
                    'civicrm_event' => 'FK_civicrm_event_payment_processor_id',
                    'civicrm_group' => 'FK_civicrm_group_saved_search_id', 
                    );
    foreach($tables as $tableName => $fKey){
      $foreignKeyExists = CRM_Core_DAO::checkConstraintExists($tableName,$fKey);
      if ($foreignKeyExists){
        CRM_Core_DAO::executeQuery("ALTER TABLE {$tableName} DROP FOREIGN KEY {$fKey}", $params, TRUE, NULL, FALSE, FALSE);
        CRM_Core_DAO::executeQuery("ALTER TABLE {$tableName} DROP INDEX {$fKey}", $params, TRUE, NULL, FALSE, FALSE);
      } 
    }
    // Drop index UI_title for civicrm_price_set 
    $domain = new CRM_Core_DAO_Domain;
    $domain->find(TRUE);
    if ($domain->locales) {
      $locales = explode(CRM_Core_DAO::VALUE_SEPARATOR, $domain->locales);
      foreach ($locales as $locale) {
        $query = "SHOW KEYS FROM `civicrm_price_set` WHERE key_name = 'UI_title_{$locale}'";
        $dao = CRM_Core_DAO::executeQuery($query, $params, TRUE, NULL, FALSE, FALSE);
        if ($dao->N) {
          CRM_Core_DAO::executeQuery("ALTER TABLE `civicrm_price_set` DROP INDEX `UI_title_{$locale}`", $params, TRUE, NULL, FALSE, FALSE);
        }
      }
    } else {
      $query = "SHOW KEYS FROM `civicrm_price_set` WHERE key_name = 'UI_title'";
      $dao = CRM_Core_DAO::executeQuery($query);
      if ($dao->N) {
        CRM_Core_DAO::executeQuery("ALTER TABLE `civicrm_price_set` DROP INDEX `UI_title`");
      }
    }

    // Some steps take a long time, so we break them up into separate
    // tasks and enqueue them separately.
    $this->addTask(ts('Upgrade DB to 4.2.alpha1: SQL'), 'task_4_2_alpha1_runSql', $rev);
    $this->addTask(ts('Upgrade DB to 4.2.alpha1: Price Sets'), 'task_4_2_alpha1_createPriceSets', $rev);
    $minContributionId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
    $maxContributionId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
    for ($startId = $minContributionId; $startId <= $maxContributionId; $startId += self::BATCH_SIZE) {
      $endId = $startId + self::BATCH_SIZE - 1;
      $title = ts('Upgrade DB to 4.2.alpha1: Contributions (%1 => %2)', array(1 => $startId, 2 => $endId));
      $this->addTask($title, 'task_4_2_alpha1_convertContributions', $startId, $endId);
    } 
    $minParticipantId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_participant');
    $maxParticipantId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_participant');
    
    for ($startId = $minParticipantId; $startId <= $maxParticipantId; $startId += self::BATCH_SIZE) {
      $endId = $startId + self::BATCH_SIZE - 1;
      $title = ts('Upgrade DB to 4.2.alpha1: Participant (%1 => %2)', array(1 => $startId, 2 => $endId));
      $this->addTask($title, 'task_4_2_alpha1_convertParticipants', $startId, $endId);
    }
    $this->addTask(ts('Upgrade DB to 4.2.alpha1: Event Profile'), 'task_4_2_alpha1_eventProfile');
  }

  function upgrade_4_2_beta2($rev) {
    // note: error conditions are also checked in setPreUpgradeMessage()
    if (defined('CIVICRM_SETTINGS_PATH')) {
      if (!preg_match(self::SETTINGS_SNIPPET_PATTERN, file_get_contents(CIVICRM_SETTINGS_PATH))) {
        if (is_writable(CIVICRM_SETTINGS_PATH)) {
          file_put_contents(CIVICRM_SETTINGS_PATH, self::SETTINGS_SNIPPET, FILE_APPEND);
        }
      }
    }
  }

  function upgrade_4_2_beta3($rev) {
    $this->addTask(ts('Upgrade DB to 4.2.beta3: SQL'), 'task_4_2_alpha1_runSql', $rev);
    $minParticipantId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_participant');
    $maxParticipantId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_participant');
    
    for ($startId = $minParticipantId; $startId <= $maxParticipantId; $startId += self::BATCH_SIZE) {
      $endId = $startId + self::BATCH_SIZE - 1;
      $title = ts('Upgrade DB to 4.2.alpha1: Participant (%1 => %2)', array(1 => $startId, 2 => $endId));
      $this->addTask($title, 'task_4_2_alpha1_convertParticipants', $startId, $endId);
    } 
  }

  function upgrade_4_2_beta5($rev) {
    // CRM-10629 Create a setting for extension URLs
    // For some reason, this isn't working when placed in the .sql file
    CRM_Core_DAO::executeQuery("
      INSERT INTO civicrm_setting(group_name,name,value,domain_id,is_domain)
      VALUES ('URL Preferences', 'extensionsURL',NULL,1,1);
    ");
  }
  
  function upgrade_4_2_0($rev) {
    $this->addTask(ts('Upgrade DB to 4.2.0: SQL'), 'task_4_2_alpha1_runSql', $rev);
  }
  
  /**
   * (Queue Task Callback)
   *
   * Upgrade code to create priceset for contribution pages and events
   */
  static function task_4_2_alpha1_createPriceSets(CRM_Queue_TaskContext $ctx, $rev) {
    $upgrade = new CRM_Upgrade_Form();
    $daoName =
      array(
        'civicrm_contribution_page' =>
        array(
          'CRM_Contribute_BAO_ContributionPage',
          CRM_Core_Component::getComponentID('CiviContribute')
        ),
        'civicrm_event' =>
        array(
          'CRM_Event_BAO_Event',
          CRM_Core_Component::getComponentID('CiviEvent')
        ),
      );
    // CRM-10613 delete bad data for membership
    self::deleteBadData('delete', $rev);
    // get all option group used for event and contribution page
    $query = "
SELECT id, name
FROM   civicrm_option_group
WHERE  name LIKE '%.amount.%' ";
    $dao = CRM_Core_DAO::executeQuery($query);
    while ($dao->fetch()) {
      $addTo = explode('.', $dao->name);
      if (CRM_Utils_Array::value(2, $addTo)) {
        $options = array ('optionGroup' => $dao->name);
        self::createPriceSet($daoName, $addTo, $options);
      }
      CRM_Core_OptionGroup::deleteAssoc($dao->name);
    }

    //create pricesets for contribution with only other amount
    $query = "
SELECT    ccp.id as contribution_page_id, ccp.is_allow_other_amount, cmb.id as membership_block_id
FROM      civicrm_contribution_page ccp
LEFT JOIN civicrm_membership_block cmb ON  cmb.entity_id = ccp.id AND cmb.entity_table = 'civicrm_contribution_page'
LEFT JOIN civicrm_price_set_entity cpse ON cpse.entity_id = ccp.id and cpse.entity_table = 'civicrm_contribution_page'
WHERE     cpse.price_set_id IS NULL";
    $dao = CRM_Core_DAO::executeQuery($query);
    $addTo = array('civicrm_contribution_page');
    while ($dao->fetch()) {
      $addTo[2] = $dao->contribution_page_id;
      $options = array ('otherAmount' =>$dao->is_allow_other_amount,
                      'membership' => $dao->membership_block_id );
      self::createPriceSet($daoName, $addTo, $options);
    }

    return TRUE;
  }

  /**
   * (Queue Task Callback)
   */
  static function task_4_2_alpha1_runSql(CRM_Queue_TaskContext $ctx, $rev) {
      $upgrade = new CRM_Upgrade_Form();
      $upgrade->processSQL($rev);

      // now rebuild all the triggers
      // CRM-9716
      CRM_Core_DAO::triggerRebuild();

      return TRUE;
  }

  /**
   * 
   * Function to Delete bad data
   */
  static function deleteBadData($deleteMembership = NULL, $rev = NULL) {
    //CRM-10613

    $query = "SELECT cc.id, cmp.membership_id 
 FROM civicrm_membership_payment cmp
 INNER JOIN `civicrm_contribution` cc ON cc.id = cmp.contribution_id
 LEFT JOIN civicrm_line_item cli ON cc.id=cli.entity_id and cli.entity_table = 'civicrm_contribution'
 INNER JOIN civicrm_membership cm ON cm.id=cmp.membership_id
 INNER JOIN civicrm_membership_type cmt ON cmt.id = cm.membership_type_id
 INNER JOIN civicrm_membership_payment cmp1 on cmp.contribution_id = cmp1.contribution_id 
 WHERE cli.entity_id IS NULL 
 GROUP BY cmp.membership_id
 HAVING COUNT(cmp.contribution_id) > 1 
 ORDER BY cmp.membership_id ASC";

    $dao = CRM_Core_DAO::executeQuery($query);
    while ($dao->fetch()) {
      self::$_deleteBadDatas[$dao->id]['memberships'][] = $dao->membership_id;
      self::$_deleteBadDatas[$dao->id]['contribution'] = $dao->id;
    }
    if ($deleteMembership) {
      $activityTypes = CRM_Core_PseudoConstant::activityType(TRUE, FALSE, FALSE, 'name');
      $activityid = array(
        array_search('Membership Signup', $activityTypes),
        array_search('Membership Renewal', $activityTypes),
        array_search('Membership Renewal Reminder', $activityTypes),
      );
      if (array_search('Change Membership Type', $activityTypes)) {
        $activityid[] = array_search('Change Membership Type', $activityTypes);
      }
      if (array_search('Change Membership Status', $activityTypes)) {
        $activityid[] = array_search('Change Membership Type', $activityTypes);
      }
      foreach (self::$_deleteBadDatas as $contributionId => $membershipIds) {
        array_pop(self::$_deleteBadDatas[$contributionId]['memberships']);
        foreach (self::$_deleteBadDatas[$contributionId]['memberships'] as $id) {
          $params = array(
            'source_record_id' => $id,
            'activity_type_id' => $activityid,
          );
          CRM_Activity_BAO_Activity::deleteActivity($params);
          $membership     = new CRM_Member_DAO_Membership();
          $membership->id = $id;
          $membership->delete();
          CRM_Core_Error::debug_log_message(ts("contribution ID = %1 , membership ID = %2 has been deleted successfully.", array (
            1 => $contributionId,
            2 => $membership->id,                                                                                                          
          )), FALSE, "Upgrade{$rev}Data");
        }
      }
    }
  }

  /**
   *
   * Function to create price sets
   */
  static function createPriceSet($daoName, $addTo,  $options = array()) {
    $query = "SELECT title FROM {$addTo[0]} where id =%1";
    $setParams['title']  = CRM_Core_DAO::singleValueQuery($query,
      array(1 => array($addTo[2], 'Integer'))
    );
    $pageTitle = strtolower(CRM_Utils_String::munge($setParams['title'], '_', 245));

    // an event or contrib page has been deleted but left the option group behind - (this may be fixed in later versions?)
    // we should probably delete the option group - but at least early exit here as the code following it does not fatal
    // CRM-10298
    if ( empty($pageTitle)) {
      return;
    }

    $optionValue = array();
    if (CRM_Utils_Array::value('optionGroup', $options)) {
      CRM_Core_OptionGroup::getAssoc($options['optionGroup'], $optionValue);
      if (empty($optionValue))
        return;
    }
      
    if (! CRM_Core_DAO::getFieldValue('CRM_Price_BAO_Set', $pageTitle, 'id', 'name', true)) {
      $setParams['name'] = $pageTitle;
    }
    else {
      $timeSec = explode(".", microtime(true));
      $setParams['name'] = $pageTitle . '_' . date('is', $timeSec[0]) . $timeSec[1];
    }
    $setParams['extends'] = $daoName[$addTo[0]][1];
    $setParams['is_quick_config'] = 1;
    $priceSet = CRM_Price_BAO_Set::create($setParams);
    CRM_Price_BAO_Set::addTo($addTo[0], $addTo[2], $priceSet->id, 1);

    $fieldParams['price_set_id'] = $priceSet->id;
    if (CRM_Utils_Array::value('optionGroup', $options)) {
      $fieldParams['html_type'] = 'Radio';
      $fieldParams['is_required'] = 1;
      if ($addTo[0] == 'civicrm_event') {
        $query = "SELECT fee_label FROM civicrm_event where id =%1";
        $fieldParams['name'] = $fieldParams['label'] = CRM_Core_DAO::singleValueQuery($query,
          array(1 => array($addTo[2], 'Integer'))
        );
        $defaultAmountColumn = 'default_fee_id';
      }
      else {
        $options['membership'] = 1;
        $fieldParams['name'] = strtolower(CRM_Utils_String::munge("Contribution Amount", '_', 245));
        $fieldParams['label'] = "Contribution Amount";
        $defaultAmountColumn = 'default_amount_id';
        $options['otherAmount'] = CRM_Core_DAO::getFieldValue('CRM_Contribute_DAO_ContributionPage', $addTo[2], 'is_allow_other_amount');
        if (CRM_Utils_Array::value('otherAmount', $options)) {
          $fieldParams['is_required'] = 0;
        }
      }
      $fieldParams['option_label'] = $optionValue['label'];
      $fieldParams['option_amount'] = $optionValue['value'];
      $fieldParams['option_weight'] = $optionValue['weight'];
      if ($defaultAmount = CRM_Core_DAO::getFieldValue($daoName[$addTo[0]][0], $addTo[2], $defaultAmountColumn)) {
        $fieldParams['default_option'] = array_search($defaultAmount, $optionValue['amount_id']);
      }
      $priceField = CRM_Price_BAO_Field::create($fieldParams);

    }
    if (CRM_Utils_Array::value('membership', $options)) {
      $dao               = new CRM_Member_DAO_MembershipBlock();
      $dao->entity_table = 'civicrm_contribution_page';
      $dao->entity_id    = $addTo[2];

      if ($dao->find(TRUE)) {
        if ($dao->membership_types) {
          $fieldParams = array(
            'name'               => strtolower(CRM_Utils_String::munge("Membership Amount", '_', 245)),
            'label'              => "Membership Amount",
            'is_required'        => $dao->is_required,
            'is_display_amounts' => $dao->display_min_fee,
            'is_active'          => $dao->is_active,
            'price_set_id'       => $priceSet->id,
            'html_type'          => 'Radio',
            'weight'             => 1,
          );
          $membershipTypes = unserialize($dao->membership_types);
          $rowcount = 0;
          foreach ($membershipTypes as $membershipType => $autoRenew) {
            $membershipTypeDetail = CRM_Member_BAO_MembershipType::getMembershipTypeDetails($membershipType);
            $rowcount++;
            $fieldParams['option_label'][$rowcount]  = $membershipTypeDetail['name'];
            $fieldParams['option_amount'][$rowcount] = $membershipTypeDetail['minimum_fee'];
            $fieldParams['option_weight'][$rowcount] = $rowcount;
            $fieldParams['membership_type_id'][$rowcount] = $membershipType;
            if ($membershipType == $dao->membership_type_default) {
              $fieldParams['default_option'] = $rowcount;
            }
          }
          $priceField = CRM_Price_BAO_Field::create($fieldParams);
          
          $setParams = array(
            'id'                   => $priceSet->id,
            'extends'              => CRM_Core_Component::getComponentID('CiviMember'),
            'contribution_type_id' => CRM_Core_DAO::getFieldValue($daoName[$addTo[0]][0], $addTo[2], 'contribution_type_id'),
          );
          CRM_Price_BAO_Set::create($setParams);
        }
      }
    }
    if (CRM_Utils_Array::value('otherAmount', $options)) {

      $fieldParams = array(
        'name'               => strtolower(CRM_Utils_String::munge("Other Amount", '_', 245)),
        'label'              => "Other Amount",
        'is_required'        => 0,
        'is_display_amounts' => 0,
        'is_active'          => 1,
        'price_set_id'       => $priceSet->id,
        'html_type'          => 'Text',
        'weight'             => 3,
      );
      $fieldParams['option_label'][1]  = "Other Amount";
      $fieldParams['option_amount'][1] = 1;
      $fieldParams['option_weight'][1] = 1;
      $priceField = CRM_Price_BAO_Field::create($fieldParams);
    }
  }

  /**
   * (Queue Task Callback)
   *
   * Find any contribution records and create corresponding line-item
   * records.
   *
   * @param $startId int, the first/lowest contribution ID to convert
   * @param $endId int, the last/highest contribution ID to convert
   */
  static function task_4_2_alpha1_convertContributions(CRM_Queue_TaskContext $ctx, $startId, $endId) {
    $upgrade = new CRM_Upgrade_Form();
    $query = "
 INSERT INTO civicrm_line_item(`entity_table` ,`entity_id` ,`price_field_id` ,`label` , `qty` ,`unit_price` ,`line_total` ,`participant_count` ,`price_field_value_id`)
 SELECT 'civicrm_contribution',cc.id, cpf.id as price_field_id, cpfv.label, 1, cc.total_amount, cc.total_amount line_total, 0, cpfv.id as price_field_value
 FROM civicrm_membership_payment cmp
 LEFT JOIN `civicrm_contribution` cc ON cc.id = cmp.contribution_id
 LEFT JOIN civicrm_line_item cli ON cc.id=cli.entity_id and cli.entity_table = 'civicrm_contribution'
 LEFT JOIN civicrm_membership cm ON cm.id=cmp.membership_id
 LEFT JOIN civicrm_membership_type cmt ON cmt.id = cm.membership_type_id
 LEFT JOIN civicrm_price_field cpf ON cpf.name = cmt.member_of_contact_id
 LEFT JOIN civicrm_price_field_value cpfv ON cpfv.membership_type_id = cm.membership_type_id
 WHERE (cc.id BETWEEN %1 AND %2) AND cli.entity_id IS NULL ;
 ";
    $sqlParams = array(
      1 => array($startId, 'Integer'),
      2 => array($endId, 'Integer'),
    );
    CRM_Core_DAO::executeQuery($query, $sqlParams);

    // create lineitems for contribution done for membership
    $sql = "
SELECT    cc.id, cmp.membership_id, cpse.price_set_id, cc.total_amount
FROM      civicrm_contribution cc
LEFT JOIN civicrm_line_item cli ON cc.id=cli.entity_id AND cli.entity_table = 'civicrm_contribution'
LEFT JOIN civicrm_membership_payment cmp ON cc.id = cmp.contribution_id
LEFT JOIN civicrm_participant_payment cpp ON cc.id = cpp.contribution_id
LEFT JOIN civicrm_price_set_entity cpse on cpse.entity_table = 'civicrm_contribution_page' AND cpse.entity_id = cc.contribution_page_id
WHERE     (cc.id BETWEEN %1 AND %2)
AND       cli.entity_id IS NULL AND cc.contribution_page_id IS NOT NULL AND cpp.contribution_id IS NULL
GROUP BY  cc.id
";
    $result = CRM_Core_DAO::executeQuery($sql, $sqlParams);

    while ($result->fetch()) {
      $sql = "
SELECT    cpf.id, cpfv.id as price_field_value_id, cpfv.label, cpfv.amount, cpfv.count
FROM      civicrm_price_field cpf
LEFT JOIN civicrm_price_field_value cpfv ON cpf.id = cpfv.price_field_id
WHERE     cpf.price_set_id = %1
";
      $lineParams = array(
        'entity_table' => 'civicrm_contribution',
        'entity_id' => $result->id,
      );
      if ($result->membership_id) {
        $sql .= " AND cpf.name = %2 AND cpfv.membership_type_id = %3 ";
        $params = array(
          '1' => array($result->price_set_id, 'Integer'),
          '2' => array('membership_amount', 'String'),
          '3' => array(CRM_Core_DAO::getFieldValue('CRM_Member_DAO_Membership', $result->membership_id, 'membership_type_id'), 'Integer'),
        );
        $res = CRM_Core_DAO::executeQuery($sql, $params);
        if ($res->fetch()) {
          $lineParams += array(
            'price_field_id' => $res->id,
            'label' => $res->label,
            'qty' => 1,
            'unit_price' => $res->amount,
            'line_total' => $res->amount,
            'participant_count' => $res->count ? $res->count : 0,
            'price_field_value_id' => $res->price_field_value_id,
          );
        }
        else {
          $lineParams['price_field_id'] = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_Field', $result->price_set_id, 'id', 'price_set_id');
          $lineParams['label'] = 'Membership Amount';
          $lineParams['qty'] = 1;
          $lineParams['unit_price'] = $lineParams['line_total'] = $result->total_amount;
          $lineParams['participant_count'] = 0;
        }
      }
      else {
        $sql .= "AND cpfv.amount = %2";
        $params = array(
          '1' => array($result->price_set_id, 'Integer'),
          '2' => array($result->total_amount, 'String'),
        );
        $res = CRM_Core_DAO::executeQuery($sql, $params);
        if ($res->fetch()) {
          $lineParams += array(
            'price_field_id' => $res->id,
            'label' => $res->label,
            'qty' => 1,
            'unit_price' => $res->amount,
            'line_total' => $res->amount,
            'participant_count' => $res->count ? $res->count : 0,
            'price_field_value_id' => $res->price_field_value_id,
          );
        }
        else {
          $params = array(
            'price_set_id' => $result->price_set_id,
            'name' => 'other_amount',
          );
          $defaults = array();
          CRM_Price_BAO_Field::retrieve($params, $defaults);
          if (!empty($defaults)) {
            $lineParams['price_field_id'] = $defaults['id'];
            $lineParams['label'] = $defaults['label'];
            $lineParams['price_field_value_id'] =
              CRM_Core_DAO::getFieldValue('CRM_Price_DAO_FieldValue', $defaults['id'], 'id', 'price_field_id');
          }
          else {
            $lineParams['price_field_id'] =
              CRM_Core_DAO::getFieldValue('CRM_Price_DAO_Field', $result->price_set_id, 'id', 'price_set_id');
            $lineParams['label'] = 'Contribution Amount';
          }
          $lineParams['qty'] = 1;
          $lineParams['participant_count'] = 0;
          $lineParams['unit_price'] = $lineParams['line_total'] = $result->total_amount;
        }
      }
      CRM_Price_BAO_LineItem::create($lineParams);
    }

    return TRUE;
  }
  
  /**
   * (Queue Task Callback)
   *
   * Find any participant records and create corresponding line-item
   * records.
   *
   * @param $startId int, the first/lowest participant ID to convert
   * @param $endId int, the last/highest participant ID to convert
   */
  static function task_4_2_alpha1_convertParticipants(CRM_Queue_TaskContext $ctx, $startId, $endId) {
    $upgrade = new CRM_Upgrade_Form();
    //create lineitems for participant in edge cases using default price set for contribution.
    $query = "
SELECT    cp.id as participant_id, cp.fee_amount, cp.fee_level,ce.is_monetary,
          cpse.price_set_id, cpf.id as price_field_id, cpfv.id as price_field_value_id
FROM      civicrm_participant cp
LEFT JOIN civicrm_line_item cli ON cli.entity_id=cp.id and cli.entity_table = 'civicrm_participant'
LEFT JOIN civicrm_event ce ON ce.id=cp.event_id
LEFT JOIN civicrm_price_set_entity cpse ON cp.event_id = cpse.entity_id and cpse.entity_table = 'civicrm_event'
LEFT JOIN civicrm_price_field cpf ON cpf.price_set_id = cpse.price_set_id
LEFT JOIN civicrm_price_field_value cpfv ON cpfv.price_field_id = cpf.id AND cpfv.label = cp.fee_level
WHERE     (cp.id BETWEEN %1 AND %2)
AND       cli.entity_id IS NULL AND cp.fee_amount IS NOT NULL";
    $sqlParams = array(
      1 => array($startId, 'Integer'),
      2 => array($endId, 'Integer'),
    );
    $dao = CRM_Core_DAO::executeQuery($query, $sqlParams);
    if ($dao->N) {
      $defaultPriceSetId = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_Set', 'default_contribution_amount', 'id', 'name');
      $priceSets = current(CRM_Price_BAO_Set::getSetDetail($defaultPriceSetId));
      $fieldID = key($priceSets['fields']);
    }

    while ($dao->fetch()) {
      $lineParams = array(
        'entity_table' => 'civicrm_participant',
        'entity_id' => $dao->participant_id,
        'label' => $dao->fee_level,
        'qty' => 1,
        'unit_price' => $dao->fee_amount,
        'line_total' => $dao->fee_amount,
        'participant_count' => 1,
      );
      if ($dao->is_monetary && $dao->price_field_id) {
        $lineParams += array(
          'price_field_id' => $dao->price_field_id,
          'price_field_value_id' => $dao->price_field_value_id,
        );
        $priceSetId = $dao->price_set_id;
      } else {
        $lineParams['price_field_id'] = $fieldID;
        $priceSetId = $defaultPriceSetId;
      }
      CRM_Price_BAO_LineItem::create($lineParams);
    }
    return TRUE;
  }

  /**
   * (Queue Task Callback)
   *
   * Create an event registration profile with a single email field CRM-9587
   */
  static function task_4_2_alpha1_eventProfile(CRM_Queue_TaskContext $ctx) {
    $upgrade = new CRM_Upgrade_Form();
    $profileTitle = ts('Your Registration Info');
    $sql = "
INSERT INTO civicrm_uf_group
  (is_active, group_type, title, help_pre, help_post, limit_listings_group_id, post_URL, add_to_group_id, add_captcha, is_map, is_edit_link, is_uf_link, is_update_dupe, cancel_URL, is_cms_user, notify, is_reserved, name, created_id, created_date, is_proximity_search)
VALUES
  (1, 'Individual, Contact', '{$profileTitle}', NULL, NULL, NULL, NULL, NULL, 0, 0, 0, 0, 0, NULL, 0, NULL, 0, 'event_registration', NULL, NULL, 0);
";
    CRM_Core_DAO::executeQuery($sql);

    $eventRegistrationId = CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');
    $sql = "
INSERT INTO civicrm_uf_field
  (uf_group_id, field_name, is_active, is_view, is_required, weight, help_post, help_pre, visibility, in_selector, is_searchable, location_type_id, phone_type_id, label, field_type, is_reserved)
VALUES
  ({$eventRegistrationId}, 'email', 1, 0, 1, 1, NULL, NULL, 'User and User Admin Only', 0, 0, NULL, NULL, 'Email Address', 'Contact', 0);
";
    CRM_Core_DAO::executeQuery($sql);

    $sql = "SELECT * FROM civicrm_event WHERE is_online_registration = 1;";
    $events = CRM_Core_DAO::executeQuery($sql);
    while ($events->fetch()) {
      // Get next weights for the event registration profile
      $nextMainWeight = $nextAdditionalWeight = 1;
      $sql = "
SELECT   weight
FROM     civicrm_uf_join
WHERE    entity_id = {$events->id} AND module = 'CiviEvent'
ORDER BY weight DESC LIMIT 1";
      $weights        = CRM_Core_DAO::executeQuery($sql);
      $weights->fetch();
      if (isset($weights->weight)) {
        $nextMainWeight += $weights->weight;
      }
      $sql = "
SELECT   weight
FROM     civicrm_uf_join
WHERE    entity_id = {$events->id} AND module = 'CiviEvent_Additional'
ORDER BY weight DESC LIMIT 1";
      $weights = CRM_Core_DAO::executeQuery($sql);
      $weights->fetch();
      if (isset($weights->weight)) {
        $nextAdditionalWeight += $weights->weight;
      }
      // Add an event registration profile to the event
      $sql = "
INSERT INTO civicrm_uf_join
  (is_active, module, entity_table, entity_id, weight, uf_group_id)
VALUES
  (1, 'CiviEvent', 'civicrm_event', {$events->id}, {$nextMainWeight}, {$eventRegistrationId});
";
      CRM_Core_DAO::executeQuery($sql);
      $sql = "
INSERT INTO civicrm_uf_join
  (is_active, module, entity_table, entity_id, weight, uf_group_id)
VALUES
  (1, 'CiviEvent_Additional', 'civicrm_event', {$events->id}, {$nextAdditionalWeight}, {$eventRegistrationId});";
      CRM_Core_DAO::executeQuery($sql);
    }
    return TRUE;
  }

  /**
   * Syntatic sugar for adding a task which (a) is in this class and (b) has
   * a high priority.
   *
   * After passing the $funcName, you can also pass parameters that will go to
   * the function. Note that all params must be serializable.
   */
  protected function addTask($title, $funcName) {
    $queue = CRM_Queue_Service::singleton()->load(array(
      'type' => 'Sql',
      'name' => CRM_Upgrade_Form::QUEUE_NAME,
    ));

    $args = func_get_args();
    $title = array_shift($args);
    $funcName = array_shift($args);
    $task = new CRM_Queue_Task(
      array(get_class($this), $funcName),
      $args,
      $title
    );
    $queue->createItem($task, array('weight' => -1));
  }
}
