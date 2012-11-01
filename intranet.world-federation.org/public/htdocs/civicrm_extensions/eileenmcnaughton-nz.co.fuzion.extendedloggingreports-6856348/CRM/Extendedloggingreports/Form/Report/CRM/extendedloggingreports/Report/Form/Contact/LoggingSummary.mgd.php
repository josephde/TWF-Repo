<?php
// This file declares a managed database record of type "ReportTemplate".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// http://wiki.civicrm.org/confluence/display/CRMDOC42/Hook+Reference
return array (
  0 =>
  array (
    'name' => 'Extended Logging Summary Report',
    'entity' => 'ReportTemplate',
    'params' =>
    array (
      'version' => 3,
      'label' => 'Logging Summary report extended for batch processes',
      'description' => 'Logging Summary report extended for batch processes',
      'class_name' => 'CRM_Extendedloggingreports_Form_Report_CRM_extendedloggingreports_Report_Form_Contact_LoggingSummary',
      'report_url' => 'contact/extendedloggingsummary',
      'component' => '',
    ),
  ),
);