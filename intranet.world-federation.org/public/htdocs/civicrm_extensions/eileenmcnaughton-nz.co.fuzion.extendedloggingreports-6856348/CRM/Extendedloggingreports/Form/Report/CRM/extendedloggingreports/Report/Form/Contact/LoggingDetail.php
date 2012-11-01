<?php

require_once 'CRM/Report/Form.php';

class CRM_Extendedloggingreports_Form_Report_CRM_extendedloggingreports_Report_Form_Contact_LoggingDetail extends CRM_Logging_ReportDetail {
  protected $log_conn_ids = array();
  protected $rows = array();
  protected $contacts = array();

  function __construct() {
    $logging = new CRM_Logging_Schema;
    $this->tables[] = 'civicrm_contact';
    $this->tables = array_merge($this->tables, array_keys($logging->customDataLogTables()));
    $this->tables[] = 'civicrm_email';
    $this->tables[] = 'civicrm_phone';
    $this->tables[] = 'civicrm_im';
    $this->tables[] = 'civicrm_openid';
    $this->tables[] = 'civicrm_website';
    $this->tables[] = 'civicrm_address';
    $this->tables[] = 'civicrm_note';
    $this->tables[] = 'civicrm_relationship';
    $this->interval = '15 MINUTE';
    $this->detail = 'logging/contact/extendedloggingdetail';
    $this->summary = 'logging/contact/summary';

    parent::__construct();

    $this->log_conn_ids = explode(',',CRM_Utils_Request::retrieve('log_conn_id', 'String', CRM_Core_DAO::$_nullObject));
    $this->time_interval = CRM_Utils_Request::retrieve('time_interval', 'String', CRM_Core_DAO::$_nullObject);
    $this->log_date    = CRM_Utils_Request::retrieve('log_date', 'String', CRM_Core_DAO::$_nullObject);

    // some extra (clunky) time handling
    if(!empty( $this->time_interval) ){
      switch ($this->time_interval){
        case 'DAY':
          $this->interval = '24 HOUR';
          break;
        case 'HOUR':
            $this->interval = '1 HOUR';
            break;
        case 'MONTH':
              $this->interval = '1 MONTH';
              break;
      }
    }
    $this->_columnHeaders = array(
        'contact' => array('title' => ts('Contact')),
        'field' => array('title' => ts('Field')),
        'from' => array('title' => ts('Changed From')),
        'to' => array('title' => ts('Changed To')),
    );
  }

  function buildQuickForm() {
    parent::buildQuickForm();

    if ($this->cid) {
      // link back to contact summary
      $this->assign('backURL', CRM_Utils_System::url('civicrm/contact/view', "reset=1&selectedChild=log&cid={$this->cid}", FALSE, NULL, FALSE));
      $this->assign('revertURL', self::$_template->get_template_vars('revertURL') . "&cid={$this->cid}");
    }
    else {
      // link back to summary report
      $this->assign('backURL', CRM_Report_Utils_Report::getNextUrl('logging/contact/summary', 'reset=1', FALSE, TRUE));
    }
  }

  protected function whoWhomWhenSql($contactId = null) {
    $contactClause = '';
    if($contactId){
      $contactClause = ' AND whom.id = %3 ';
    }

    return "
SELECT who.id who_id, who.display_name who_name, whom.id whom_id, whom.display_name whom_name, l.is_deleted
FROM `{$this->db}`.log_civicrm_contact l
LEFT JOIN civicrm_contact who ON (l.log_user_id = who.id)
JOIN civicrm_contact whom ON (l.id = whom.id)
WHERE log_action = 'Update'  $contactClause AND log_conn_id = %1 AND log_date BETWEEN DATE_SUB(%2, INTERVAL {$this->interval} ) AND DATE_ADD(%2, INTERVAL {$this->interval} ) ORDER BY log_date DESC LIMIT 1
";
  }

  function alterDisplay(&$rows) {

    foreach ($rows as $key => &$row) {
      if(!empty($row['contact_id']) || !empty($row['original_contact_id'])){
        $contactId = CRM_Utils_Array::value('contact_id', $row);
        if(empty($contactId)){
          $contactId = CRM_Utils_Array::value('original_contact_id',$row);
        }

        $dao = CRM_Core_DAO::executeQuery("SELECT display_name FROM civicrm_contact WHERE id = $contactId");
        while($dao->fetch()){
          $display_name = $dao->display_name;
        }
        $row['contact'] = " $display_name";
        $row['contact_link'] = CRM_Utils_System::url('civicrm/contact/view', 'reset=1&cid=' . $row['contact_id']);
        $row['contact_hover'] = ts("See this contact");
      }
  }
  }

  function buildRows($sql, &$rows) {
    // safeguard for when there aren’t any log entries yet
    if (!$this->log_conn_id or !$this->log_date) {
      return;
    }

    foreach ($this->tables as $table) {
      $this->diffsInTable($table);
    }
    if(is_array($this->contacts)){
      array_multisort($this->contacts,$this->rows);
    }
    $rows = $this->rows;
  }

  protected function diffsInTable($table) {

    foreach ($this->log_conn_ids as $log_conn_id){
      $this->log_conn_id = $log_conn_id;
      $differ = new CRM_Logging_Differ($log_conn_id, $this->log_date, $this->interval);
      $diffs = $differ->diffsInTable($table, $this->cid);
      // return early if nothing found
      if (empty($diffs)) {
        continue;
      }

      list($titles, $values) = $differ->titlesAndValuesForTable($table);
      // populate $rows with only the differences between $changed and $original (skipping certain columns and NULL ↔ empty changes unless raw requested)
      $skipped = array('contact_id', 'entity_id', 'id');
      foreach ($diffs as $diff) {
        $field = $diff['field'];
        $from  = $diff['from'];
        $to    = $diff['to'];
        $contactId = $diff['contact_id'];
        $originalcontactId = $diff['original_contact_id'];

        if ($this->raw) {
          $field = "$table.$field";
        }
        else {
          //this is the part that differs from parent - we want to return contact when an email moves from one to anohter (e.g a merge or batch
          if (in_array($field, $skipped)) {
            if(empty($from) || empty($to)){
              continue;
            }

            if($field == 'contact_id'){
              $from .= " - " . civicrm_api('contact','getvalue',array('version' => 3, 'id' => $from, 'return' => 'display_name'));
              $to .= " - " . civicrm_api('contact','getvalue',array('version' => 3, 'id' => $to, 'return' => 'display_name'));
            }
            $field = $table . " changed owner :" .$field;

          }
          //end of changes = rest like parent
          // $differ filters out === values; for presentation hide changes like 42 → '42'
          if ($from == $to) {
            continue;
          }
          // only in PHP: '0' == false and null == false but '0' != null
          if ($from == FALSE and $to == FALSE) {
            continue;
          }

          // CRM-7251: special-case preferred_communication_method
          if ($field == 'preferred_communication_method') {
            $froms = array();
            $tos = array();
            foreach (explode(CRM_Core_DAO::VALUE_SEPARATOR, $from) as $val) $froms[] = CRM_Utils_Array::value($val, $values[$field]);
            foreach (explode(CRM_Core_DAO::VALUE_SEPARATOR, $to) as $val) $tos[] = CRM_Utils_Array::value($val, $values[$field]);
            $from = implode(', ', array_filter($froms));
            $to = implode(', ', array_filter($tos));
          }

          if (isset($values[$field][$from])) {

            $from = $values[$field][$from];

          }
          if (isset($values[$field][$to])) {
            $to = $values[$field][$to];
          }
          if (isset($titles[$field])) {
            $field = $titles[$field];
          }
          if ($diff['action'] == 'Insert') {
            $from = '';
          }
          if ($diff['action'] == 'Delete') {
            $to = '(deleted)';
          }
        }

        $this->rows[] = array('field' => $field . " (id: {$diff['id']})", 'from' => $from, 'to' => $to, 'contact_id' => $contactId, 'original_contact_id' => $originalcontactId);
        $this->contacts[] = str_pad(!empty($contactId)?$contactId : $originalcontactId, 10, STR_PAD_LEFT)  . str_pad($diff['id'], 10, STR_PAD_RIGHT) ;

      }
    }

  }
}

