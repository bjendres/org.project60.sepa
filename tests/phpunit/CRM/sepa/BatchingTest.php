<?php
/*-------------------------------------------------------+
| Project 60 - SEPA direct debit                         |
| Copyright (C) 2013-2014 SYSTOPIA                       |
| Author: N. Bochan (bochan -at- systopia.de)            |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

require_once 'CiviTest/CiviUnitTestCase.php';

/**
 * SEPA Unit Tests
 *
 * Batching Algorithm
 *
 */
class CRM_sepa_BatchingTest extends CiviUnitTestCase {
  private $tablesToTruncate = array("civicrm_sdd_creditor",
                                    //"civicrm_contact",
                                    "civicrm_contribution",
                                    "civicrm_contribution_recur",
                                    "civicrm_sdd_mandate",
                                    "civicrm_sdd_contribution_txgroup",
                                    "civicrm_sdd_txgroup",
                                    "civicrm_sdd_file",
                                    "civicrm_line_item",
                                    "civicrm_financial_item",
                                    "civicrm_financial_trxn"
                                    );
  private $creditorId = NULL;

  function setUp() {
    parent::setUp();

    // FIXME: there seems to be a bug in civix, call this explicitely until fixed:
    sepa_civicrm_install();

    $this->quickCleanup($this->tablesToTruncate);

    // create a contact
    $this->creditorId = $this->individualCreate();

    // create a creditor
    $this->assertDBQuery(NULL, "INSERT INTO `civicrm_tests_dev`.`civicrm_sdd_creditor` (`id`, `creditor_id`, `identifier`, `name`, `address`, `country_id`, `iban`, `bic`, `mandate_prefix`, `payment_processor_id`, `category`, `tag`, `mandate_active`, `sepa_file_format_id`) VALUES ('3', '%1', 'TESTCREDITORID', 'TESTCREDITOR', '104 Wayne Street', '1082', '0000000000000000000000', 'COLSDE22XXX', 'TEST', '0', 'MAIN', NULL, '1', '1');", array(1 => array($this->creditorId, "Int")));
  }

  function tearDown() {
    error_reporting(E_ALL & ~E_NOTICE);
    $this->quickCleanup($this->tablesToTruncate);
    $this->cleanTempDirs();
    $this->unsetExtensionSystem();
  }


  /**
   * Test update of one-off (single payment) contributions
   *
   * @author niko bochan
   */
  public function testBatchingUpdateOOFF() {
    // create a contact
    $contactId = $this->individualCreate();
    // create a contribution
    $txmd5 = md5(date("YmdHis"));
    $txref = "SDD-TEST-OOFF-" . $txmd5;
    $cparams = array(
      "contact_id" => $contactId,
      "receive_date" => date("YmdHis"),
      "total_amount" => 333.94,
      "currency" => "EUR",
      "financial_type_id" => 1,
      "trxn_id" => $txref,
      "invoice_id" => $txref,
      "source" => "Test",
      "contribution_status_id" => 2,
    );

    $contrib = $this->callAPISuccess("contribution", "create", $cparams);
    $contrib = $contrib["values"][ $contrib["id"] ];

    // create a mandate
    $apiParams = array(
      "type" => "OOFF",
      "reference" => $txmd5,
      "status" => "OOFF",
      "source" => "TestSource",
      "date" => date("Y-m-d H:i:s"),
      "creditor_id" => "3",
      "contact_id" => $contactId,
      "iban" => "0000000000000000000000",
      "bic"  => "COLSDE22XXX",
      "creation_date" => date("Y-m-d H:i:s"),
      "entity_table" => "civicrm_contribution",
      "entity_id" => $contrib["id"],
      );

    $this->callAPISuccess("SepaMandate", "create", $apiParams);

    // create another contact
    $contactId = $this->individualCreate();
    // create another contribution
    $txmd5 = md5(date("YmdHis")."noduplicate");
    $txref = "SDD-TEST-OOFF-" . $txmd5;
    $cparams = array(
      "contact_id" => $contactId,
      "receive_date" => date("YmdHis"),
      "total_amount" => 123.45,
      "currency" => "EUR",
      "financial_type_id" => 1,
      "trxn_id" => $txref,
      "invoice_id" => $txref,
      "source" => "Test",
      "contribution_status_id" => 2,
    );
    $contrib = $this->callAPISuccess("contribution", "create", $cparams);
    $contrib = $contrib["values"][ $contrib["id"] ];
    // create another mandate
    $apiParams = array(
      "type" => "OOFF",
      "reference" => $txmd5,
      "status" => "OOFF",
      "source" => "TestSource",
      "date" => date("Y-m-d H:i:s"),
      "creditor_id" => "3",
      "contact_id" => $contactId,
      "iban" => "0000000000000000000010",
      "bic"  => "COLSDE22XXX",
      "creation_date" => date("Y-m-d H:i:s"),
      "entity_table" => "civicrm_contribution",
      "entity_id" => $contrib["id"]
      );

    $this->callAPISuccess("SepaMandate", "create", $apiParams);

    $result = $this->callAPISuccess("SepaAlternativeBatching", "update", array("type"=>"OOFF"));
    // test whether exactly one txgroup has been created
    $this->assertDBQuery(1, 'select count(*) from civicrm_sdd_txgroup;', array());
    // check txgroup attributes
    $collectionDate = date('Y-m-d', strtotime('+14 days')); // TODO: Use config file instead
    $searchParams = array(
      "id" => 1,
      //"reference" => sprintf("TXG-3-OOFF-%s", $collectionDate), TODO: bug 
      "type" => "OOFF",
      //"collection_date" => sprintf("%s 00:00:00", $collectionDate), TODO: bug
      "latest_submission_date" => sprintf("%s 00:00:00", date('Y-m-d')),
      "created_date" => sprintf("%s 00:00:00", date('Y-m-d')),
      "status_id" => 1,
      "sdd_creditor_id" => 3
    );
    $this->assertDBCompareValues("CRM_Sepa_DAO_SEPATransactionGroup", array("id" => 1), $searchParams);
  }

  /**
   * Test update of recurring payments
   *
   * @author niko bochan
   */
  public function testBatchingUpdateRCUR() {
    $result = $this->createContactAndRecurContrib();

    // create a mandate
    $txmd5 = md5(date("YmdHis")."noduplicate1");
    $apiParams = array(
      "type" => "RCUR",
      "reference" => $txmd5,
      "status" => "FRST",
      "source" => "TestSource",
      "date" => date("Y-m-d H:i:s"),
      "creditor_id" => "3",
      "contact_id" => $result["contactId"],
      "iban" => "0000000000000000010001",
      "bic"  => "COLSDE22XXX",
      "creation_date" => date("Y-m-d H:i:s"),
      "entity_table" => "civicrm_contribution_recur",
      "entity_id" => $result["contribution"]["id"],
      );

    $this->callAPISuccess("SepaMandate", "create", $apiParams);

    // create another mandate
    $txmd5 = md5(date("YmdHis")."noduplicate2");
    $apiParams = array(
      "type" => "RCUR",
      "reference" => $txmd5,
      "status" => "FRST",
      "source" => "TestSource",
      "date" => date("Y-m-d H:i:s"),
      "creditor_id" => "3",
      "contact_id" => $result["contactId"],
      "iban" => "0000000000000000000110",
      "bic"  => "COLSDE22XXX",
      "creation_date" => date("Y-m-d H:i:s"),
      "entity_table" => "civicrm_contribution_recur",
      "entity_id" => $result["contribution"]["id"],
      );

    $this->callAPISuccess("SepaMandate", "create", $apiParams);
    $this->callAPISuccess("SepaAlternativeBatching", "update", array("type" => "FRST"));

    // test whether exactly one txgroup has been created
    $this->assertDBQuery(1, 'select count(*) from civicrm_sdd_txgroup;', array());
    // check txgroup attributes
    $collectionDate = date('Y-m-d', strtotime('+14 days'));
    $searchParams = array(
      "id" => 1,
      "reference" => sprintf("TXG-3-FRST-%s", $collectionDate),
      "type" => "FRST",
      "collection_date" => sprintf("%s 00:00:00", $collectionDate),
      "created_date" => sprintf("%s 00:00:00", date('Y-m-d')),
      "status_id" => 1,
      "sdd_creditor_id" => 3
    );
    $this->assertDBCompareValues("CRM_Sepa_DAO_SEPATransactionGroup", array("id" => 1), $searchParams);
  }

  /**
   * Try to call update method with invalid batching mode
   *
   * @author niko bochan
   */
  public function testBatchingWithInvalidMode() {
     $this->callAPIFailure("SepaAlternativeBatching", "update", array("type" => "INVALIDBATCHINGMODE"));
  }

  /**
   * Try to call update method with invalid parameters
   *
   * @author niko bochan
   */
  public function testBatchingWithInvalidParameters() {
     $this->callAPIFailure("SepaAlternativeBatching", "update", 2142);
  }

  /**
   * Test group closing
   *
   * @author niko bochan
   */
  public function testCloseGroup() {
    // create a contact
    $contactId = $this->individualCreate();
    // create a contribution
    $txmd5 = md5(date("YmdHis"));
    $txref = "SDD-TEST-OOFF-" . $txmd5;
    $cparams = array(
      "contact_id" => $contactId,
      "receive_date" => date("YmdHis"),
      "total_amount" => 333.94,
      "currency" => "EUR",
      "financial_type_id" => 1,
      "trxn_id" => $txref,
      "invoice_id" => $txref,
      "source" => "Test",
      "contribution_status_id" => 2,
    );

    $contrib = $this->callAPISuccess("contribution", "create", $cparams);
    $contrib = $contrib["values"][ $contrib["id"] ];

    // create a mandate
    $apiParams = array(
      "type" => "OOFF",
      "reference" => $txmd5,
      "status" => "OOFF",
      "source" => "TestSource",
      "date" => date("Y-m-d H:i:s"),
      "creditor_id" => "3",
      "contact_id" => $contactId,
      "iban" => "0000000000000000000000",
      "bic"  => "COLSDE22XXX",
      "creation_date" => date("Y-m-d H:i:s"),
      "entity_table" => "civicrm_contribution",
      "entity_id" => $contrib["id"],
      );

    $this->callAPISuccess("SepaMandate", "create", $apiParams);
    // update txgroup
    $this->callAPISuccess("SepaAlternativeBatching", "update", array("type"=>"OOFF"));
    // close the group
    $this->callAPISuccess("SepaAlternativeBatching", "close", array("txgroup_id"=>1));
    // check txgroup attributes
    $searchParams = array(
      "id" => 1,
      "status_id" => 2 // the group should be closed
    );
    $this->assertDBCompareValues("CRM_Sepa_DAO_SEPATransactionGroup", array("id" => 1), $searchParams);
    // check whether the contribution has been marked as "in progress"
    $searchParams = array(
      "id" => 1,
      "contribution_status_id" => (int) CRM_Core_OptionGroup::getValue('contribution_status', 'In Progress', 'name')
    );
    $this->assertDBCompareValues("CRM_Contribute_DAO_Contribution", array("id" => 1), $searchParams);
  }

  /**
   * Try to call close method with empty parameters
   *
   * @author niko bochan
   */
  public function testCloseWithEmptyParameters() {
     $this->callAPIFailure("SepaAlternativeBatching", "close", array());
  }

  /**
   * Try to close an invalid group
   *
   * @author niko bochan
   */
  public function testCloseWithInvalidParameters() {
    $this->callAPIFailure("SepaAlternativeBatching", "close", array("txgroup_id" => "INVALIDTXGID"));
  }

  // Disabled until we fix https://github.com/Project60/sepa_dd/issues/138

  /**
   * Test if groups are marked correctly as received
   *
   * @author niko bochan
   */
  /*public function testReceivedGroup() {
    //$this->assertDBQuery(NULL, "INSERT INTO `civicrm_tests_dev`.`civicrm_option_value` (`id`, `option_group_id`, `label`, `value`, `name`, `grouping`, `filter`, `is_default`, `weight`, `description`, `is_optgroup`, `is_reserved`, `is_active`, `component_id`, `domain_id`, `visibility_id`) VALUES (NULL, '67', 'Received', '6', 'Received', NULL, '0', NULL, '5', NULL, '0', '0', '1', NULL, NULL, NULL);");
    // create a contact
    $contactId = $this->individualCreate();
    // create a recurring contribution
    $txmd5 = md5(date("YmdHis") . "noduplicate1");
    $txref = "SDD-TEST-RCUR-" . $txmd5;
    $cparams = array(
      'contact_id' => $contactId,
      'frequency_interval' => '1',
      'frequency_unit' => 'month',
      'amount' => 1337.42,
      'contribution_status_id' => 1,
      'start_date' => date("Ymd", strtotime("+14 days")),
      'currency' => "EUR",
      'financial_type_id' => 1
    );

    $contrib = $this->callAPISuccess("contribution_recur", "create", $cparams);
    $contrib = $contrib["values"][ $contrib["id"] ];

    // create a mandate
    $apiParams = array(
      "type" => "RCUR",
      "reference" => $txmd5,
      "status" => "FRST",
      "source" => "TestSource",
      "date" => date("Y-m-d H:i:s"),
      "creditor_id" => "3",
      "contact_id" => $contactId,
      "iban" => "0000000000000000010001",
      "bic"  => "COLSDE22XXX",
      "creation_date" => date("Y-m-d H:i:s"),
      "entity_table" => "civicrm_contribution_recur",
      "entity_id" => $contrib["id"],
      );

    $this->callAPISuccess("SepaMandate", "create", $apiParams);

    // create another contact
    $contactId = $this->individualCreate();
    // create another recurring contribution
    $txmd5 = md5(date("YmdHis") . "noduplicate2");
    $txref = "SDD-TEST-RCUR-" . $txmd5;
    $cparams = array(
      'contact_id' => $contactId,
      'frequency_interval' => '1',
      'frequency_unit' => 'month',
      'amount' => 543.21,
      'contribution_status_id' => 1,
      'start_date' => date("Ymd", strtotime("+10 days")),
      'currency' => "EUR",
      'financial_type_id' => 1
    );

    $contrib = $this->callAPISuccess("contribution_recur", "create", $cparams);
    $contrib = $contrib["values"][ $contrib["id"] ];

    // create another mandate
    $apiParams = array(
      "type" => "RCUR",
      "reference" => $txmd5,
      "status" => "FRST",
      "source" => "TestSource",
      "date" => date("Y-m-d H:i:s"),
      "creditor_id" => "3",
      "contact_id" => $contactId,
      "iban" => "0000000000000000000110",
      "bic"  => "COLSDE22XXX",
      "creation_date" => date("Y-m-d H:i:s"),
      "entity_table" => "civicrm_contribution_recur",
      "entity_id" => $contrib["id"],
      );

    $this->callAPISuccess("SepaMandate", "create", $apiParams);
    // update txgroup
    $this->callAPISuccess("SepaAlternativeBatching", "update", array("type" => "FRST"));
    // close the group
    $this->callAPISuccess("SepaAlternativeBatching", "close", array("txgroup_id"=>1));
    // mark the group as received
    $this->callAPISuccess("SepaAlternativeBatching", "received", array("txgroup_id"=>1));
    // check txgroup attributes
    $searchParams = array(
      "id" => 1,
      "status_id" => (int) CRM_Core_OptionGroup::getValue('batch_status', 'Received', 'name')
    );
    $this->assertDBCompareValues("CRM_Sepa_DAO_SEPATransactionGroup", array("id" => 1), $searchParams);
    // TODO: Second Contribution
  }*/

  /**
   * Try to call API with empty parameters
   *
   * @author niko bochan
   */
  public function testReceivedWithEmptyParameters() {
     $this->callAPIFailure("SepaAlternativeBatching", "received", array());
  }

  /**
   * Try to set an invalid group to received
   *
   * @author niko bochan
   */
  public function testReceivedWithInvalidParameters() {
    $this->callAPIFailure("SepaAlternativeBatching", "received", array("txgroup_id" => "INVALIDTXGID"));
  }

  /**
   * Test if ended/old groups are closed
   *
   * @author niko bochan
   */
  public function testCloseEndedGroup() {
    // create a contact
    $contactId = $this->individualCreate();
    // create a recurring contribution
    $txmd5 = md5(date("YmdHis"));
    $cparams = array(
      'contact_id' => $contactId,
      'frequency_interval' => '1',
      'frequency_unit' => 'month',
      'amount' => 1337.42,
      'contribution_status_id' => 1,
      'start_date' => date("Ymd", strtotime("-100 days")),
      'end_date' => date("Ymd", strtotime("-50 days")),
      'currency' => "EUR",
      'financial_type_id' => 1
    );

    $contrib = $this->callAPISuccess("contribution_recur", "create", $cparams);
    $contrib = $contrib["values"][ $contrib["id"] ];

    // create a mandate
    $apiParams = array(
      "type" => "RCUR",
      "reference" => $txmd5,
      "status" => "FRST",
      "source" => "TestSource",
      "date" => date("Y-m-d H:i:s", strtotime("-110 days")),
      "creditor_id" => "3",
      "contact_id" => $contactId,
      "iban" => "0000000000000000010001",
      "bic"  => "COLSDE22XXX",
      "creation_date" => date("Y-m-d H:i:s", strtotime("-110 days")),
      "entity_table" => "civicrm_contribution_recur",
      "entity_id" => $contrib["id"]
      );

    $this->callAPISuccess("SepaMandate", "create", $apiParams);

    // update txgroup
    $this->callAPISuccess("SepaAlternativeBatching", "update", array("type" => "FRST"));
    // close the group
    $this->callAPISuccess("SepaAlternativeBatching", "closeended", array("txgroup_id"=>1));
    // Check whether the mandate has been closed
    $searchParams = array(
      "id" => 1,
      "status" => 'COMPLETE'
    );
    $this->assertDBCompareValues("CRM_Sepa_DAO_SEPAMandate", array("id" => 1), $searchParams);
    // Check whether contribution has been flagged as ended
    $searchParams = array(
      "id" => 1,
      "contribution_status_id" => (int) CRM_Core_OptionGroup::getValue('contribution_status', 'Completed', 'name')
    );
    $this->assertDBCompareValues("CRM_Contribute_DAO_ContributionRecur", array("id" => 1), $searchParams);
  }

  /**
   * Test support of multiple creditors
   *
   * @author niko bochan
   */
  public function testMultipleCreditors() {
    // create a contact
    $secondCreditorId = $this->individualCreate();
    // create a creditor
    $this->assertDBQuery(NULL, "INSERT INTO `civicrm_tests_dev`.`civicrm_sdd_creditor` (`id`, `creditor_id`, `identifier`, `name`, `address`, `country_id`, `iban`, `bic`, `mandate_prefix`, `payment_processor_id`, `category`, `tag`, `mandate_active`, `sepa_file_format_id`) VALUES ('4', '%1', 'TESTCREDITORID', 'TESTCREDITOR', '108 Wayne Street', '1082', '0000000000000000000000', 'COLSDE44XXX', 'TEST', '0', 'MAIN', NULL, '1', '1');", array(1 => array($secondCreditorId, "Int")));
  
    $result = $this->createContactAndRecurContrib();

    // create a mandate
    $txmd5 = md5(date("YmdHis") . "noduplicate1");
    $apiParams = array(
      "type" => "RCUR",
      "reference" => $txmd5,
      "status" => "FRST",
      "source" => "TestSource",
      "date" => date("Y-m-d H:i:s"),
      "creditor_id" => "4",
      "contact_id" => $result["contactId"],
      "iban" => "0000000000000000010001",
      "bic"  => "COLSDE22XXX",
      "creation_date" => date("Y-m-d H:i:s"),
      "entity_table" => "civicrm_contribution_recur",
      "entity_id" => $result["contribution"]["id"],
      );

    $this->callAPISuccess("SepaMandate", "create", $apiParams);

    $result = $this->createContactAndRecurContrib();

    // create another mandate
    $txmd5 = md5(date("YmdHis") . "noduplicate2");
    $apiParams = array(
      "type" => "RCUR",
      "reference" => $txmd5,
      "status" => "FRST",
      "source" => "TestSource",
      "date" => date("Y-m-d H:i:s"),
      "creditor_id" => "3",
      "contact_id" => $result["contribution"]["id"],
      "iban" => "0000000000000000000110",
      "bic"  => "COLSDE22XXX",
      "creation_date" => date("Y-m-d H:i:s"),
      "entity_table" => "civicrm_contribution_recur",
      "entity_id" => $result["contribution"]["id"],
      );

    $this->callAPISuccess("SepaMandate", "create", $apiParams);
    $this->callAPISuccess("SepaAlternativeBatching", "update", array("type" => "FRST"));

    // test whether exactly one txgroup has been created
    $this->assertDBQuery(2, 'select count(*) from civicrm_sdd_txgroup;', array());
  }

  /**
   * Test whether there is an error returned when we set a
   * txgroup status to 'received' before closing the group
   *
   * @author niko bochan
   */
  public function testReceivedBeforeClosed() {
    $result = $this->createContactAndRecurContrib();

    // create a mandate
    $txmd5 = md5(date("YmdHis") . "noduplicate1");
    $apiParams = array(
      "type" => "RCUR",
      "reference" => $txmd5,
      "status" => "FRST",
      "source" => "TestSource",
      "date" => date("Y-m-d H:i:s"),
      "creditor_id" => "3",
      "contact_id" => $result["contactId"],
      "iban" => "0000000000000000010001",
      "bic"  => "COLSDE22XXX",
      "creation_date" => date("Y-m-d H:i:s"),
      "entity_table" => "civicrm_contribution_recur",
      "entity_id" => $result["contribution"]["id"],
      );

    $this->callAPISuccess("SepaMandate", "create", $apiParams);

    // create another contact
    $result = $this->createContactAndRecurContrib();

    // create another mandate
    $txmd5 = md5(date("YmdHis") . "noduplicate2");
    $apiParams = array(
      "type" => "RCUR",
      "reference" => $txmd5,
      "status" => "FRST",
      "source" => "TestSource",
      "date" => date("Y-m-d H:i:s"),
      "creditor_id" => "3",
      "contact_id" => $result["contactId"],
      "iban" => "0000000000000000010001",
      "bic"  => "COLSDE22XXX",
      "creation_date" => date("Y-m-d H:i:s"),
      "entity_table" => "civicrm_contribution_recur",
      "entity_id" => $result["contribution"]["id"],
      );

    $this->callAPISuccess("SepaMandate", "create", $apiParams);
    // update txgroup
    $this->callAPISuccess("SepaAlternativeBatching", "update", array("type" => "FRST"));
    // mark the group as received
    $this->callAPIFailure("SepaAlternativeBatching", "received", array("txgroup_id"=>1));
  }

  /**
   * Test if an update after closing a group works correctly
   *
   * @see https://github.com/Project60/sepa_dd/issues/128
   * @author niko bochan
   */
  public function testUpdateAfterClosedRCUR() {
    // this test creates 5 contributions
    $contrib_count = 5;

    for ($i=0; $i < $contrib_count; $i++) { 
      $result = $this->createContactAndRecurContrib();

      // 1.1 create a mandate
      $txmd5 = md5(date("YmdHis") . rand(1,100));
      $apiParams = array(
        "type" => "RCUR",
        "reference" => $txmd5,
        "status" => "FRST",
        "source" => "TestSource",
        "date" => date("Y-m-d H:i:s", strtotime("-130 days")),
        "creditor_id" => "3",
        "contact_id" => $result["contactId"],
        "iban" => "0000000000000000010001",
        "bic"  => "COLSDE22XXX",
        "creation_date" => date("Y-m-d H:i:s", strtotime("-130 days")),
        "entity_table" => "civicrm_contribution_recur",
        "entity_id" => $result["contribution"]["id"]
        );

      $this->callAPISuccess("SepaMandate", "create", $apiParams);
    }

    // close the group
    $this->callAPISuccess("SepaAlternativeBatching", "closeended", array("txgroup_id"=>1));

    // update txgroup
    $this->callAPISuccess("SepaAlternativeBatching", "update", array("type" => "FRST"));
    $this->callAPISuccess("SepaAlternativeBatching", "update", array("type" => "FRST"));
    
    $this->assertDBQuery(1, 'select count(*) from civicrm_sdd_txgroup;', array());
    $this->assertDBQuery($contrib_count, 'select count(*) from civicrm_contribution_recur;', array());
  }


  /**
   * Test if the correct payment instrument is used throughout the RCUR status changes
   * 
   * @see https://github.com/Project60/sepa_dd/issues/124
   * @author endres -at- systopia.de
   */
  public function testCorrectPaymentInstrumentSet() {
    // read the payment instrument ids  
    $payment_instrument_FRST = (int) CRM_Core_OptionGroup::getValue('payment_instrument', 'FRST', 'name');
    $this->assertNotEmpty($payment_instrument_FRST, "Could not find the 'FRST' payment instrument.");
    $payment_instrument_RCUR = (int) CRM_Core_OptionGroup::getValue('payment_instrument', 'RCUR', 'name');
    $this->assertNotEmpty($payment_instrument_RCUR, "Could not find the 'RCUR' payment instrument.");

    // create a contact
    $result = $this->createContactAndRecurContrib();

    // create a mandate
    $apiParams = array(
      "type" => "RCUR",
      "status" => "FRST",
      "reference" => md5(microtime()),
      "source" => "TestSource",
      "date" => date("Y-m-d H:i:s"),
      "creditor_id" => $this->getCreditor(),
      "contact_id" => $result["contactId"],
      "iban" => "0000000000000000010001",
      "bic"  => "COLSDE22XXX",
      "creation_date" => date("Y-m-d H:i:s"),
      "entity_table" => "civicrm_contribution_recur",
      "entity_id" => $result["contribution"]["id"],
      );
    $mandate = $this->callAPISuccess("SepaMandate", "create", $apiParams);

    // check the batching creates a contribution with ther right payment instrument
    $sql = "select count(*) from civicrm_contribution where payment_instrument_id = '%1';";
    $this->assertDBQuery(0, $sql, array(1 => array($payment_instrument_FRST, 'Integer'))); // "There is already a payment in the DB. Weird"
    $this->callAPISuccess("SepaAlternativeBatching", "update", array("type" => "FRST"));
    $this->assertDBQuery(1, $sql, array(1 => array($payment_instrument_FRST, 'Integer'))); // "Batching has not created a correct payment."

    // now change the status of the mandate to 'RCUR'
    // FIXME: do this via the closegroup API
    $this->callAPISuccess("SepaMandate", "create", array('id' => $mandate['id'], 'status' => 'RCUR'));

    // again: check the batching creates a contribution with ther right payment instrument
    $this->assertDBQuery(0, $sql, array(1 => array($payment_instrument_RCUR, 'Integer'))); // "There is already a payment in the DB. Weird"
    $this->callAPISuccess("SepaAlternativeBatching", "update", array("type" => "RCUR"));
    $this->assertDBQuery(1, $sql, array(1 => array($payment_instrument_RCUR, 'Integer'))); // "Batching has not created a correct payment."
  }

  /**
   * See if the status change from FRST to RCUR works correctly
   * 
   * @see https://github.com/Project60/sepa_dd/issues/128
   * @author endres -at- systopia.de
   */
  public function testFRSTtoRCURswitch() {
    // 0) select cycle day so that the submission would be due today
    $frst_notice = CRM_Core_BAO_Setting::getItem('SEPA Direct Debit Preferences', 'batching_alt_FRST_notice');
    $this->assertNotEmpty($frst_notice, "No FRST notice period specified!");
    $rcur_notice = CRM_Core_BAO_Setting::getItem('SEPA Direct Debit Preferences', 'batching_alt_RCUR_notice');
    $this->assertNotEmpty($rcur_notice, "No RCUR notice period specified!");
    $this->assertEquals($frst_notice, $rcur_notice, "Notice periods should be the same.");
    $cycle_day = date("d", strtotime("+$frst_notice days"));

    // 1) create a FRST mandate, due for collection right now
    $mandate = $this->createMandate(array('type'=>'RCUR', 'status'=>'FRST'), array('cycle_day' => $cycle_day));
    $mandate_before_batching = $this->callAPISuccess("SepaMandate", "getsingle", array("id" => $mandate['id']));
    $this->assertTrue(($mandate_before_batching['status']=='FRST'), "Mandate was not created in the correct status.");

    // 2) call batching
    $this->callAPISuccess("SepaAlternativeBatching", "update", array("type" => "FRST"));
    $this->callAPISuccess("SepaAlternativeBatching", "update", array("type" => "RCUR"));


    // 3) find and check the created contribution
    $contribution_recur_id = $mandate['entity_id'];
    $this->assertNotEmpty($contribution_recur_id, "No entity set in mandate");
    $contribution_id = CRM_Core_DAO::singleValueQuery("SELECT id FROM civicrm_contribution WHERE contribution_recur_id=$contribution_recur_id;");
    $this->assertNotEmpty($contribution_id, "Couldn't find created contribution.");    
    $this->assertDBQuery(1, "SELECT count(id) FROM civicrm_contribution WHERE contribution_recur_id=$contribution_recur_id;");

    // 4) close the group
    $this->assertDBQuery(1, "SELECT count(txgroup_id) FROM civicrm_sdd_contribution_txgroup WHERE contribution_id=$contribution_id;");
    $txgroup_id = CRM_Core_DAO::singleValueQuery("SELECT txgroup_id FROM civicrm_sdd_contribution_txgroup WHERE contribution_id=$contribution_id;");
    $this->assertNotEmpty($txgroup_id, "Contribution was not added to a group!");
    $txgroup = $this->callAPISuccess("SepaTransactionGroup", "getsingle", array("id" => $txgroup_id));
    $latest_submission_date = substr($txgroup['latest_submission_date'], 0, 10);
    $this->assertEquals(date('Y-m-d'), $latest_submission_date, "The group should be due today! Check test configuration!");
    $this->callAPISuccess("SepaAlternativeBatching", "close", array("txgroup_id" => $txgroup_id));

    // 5) check if contribution and mandate are in the correct state (RCUR)
    $mandate_after_batching = $this->callAPISuccess("SepaMandate", "getsingle", array("id" => $mandate['id']));
    $this->assertTrue(($mandate_after_batching['status']=='RCUR'), "Mandate was not switched to status 'RCUR' after group was closed");
    $contribution = $this->callAPISuccess("Contribution", "getsingle", array("id" => $contribution_id));
    $this->assertEquals('FRST', $contribution['contribution_payment_instrument'], "Created contribution does not have payment instrument 'FRST'!");
    
    // 5) call batching again
    $group_count_before = CRM_Core_DAO::singleValueQuery("SELECT COUNT(id) FROM civicrm_sdd_txgroup;");
    $contribution_count_before = CRM_Core_DAO::singleValueQuery("SELECT COUNT(id) FROM civicrm_contribution;");
    $this->callAPISuccess("SepaAlternativeBatching", "update", array("type" => "FRST"));
    $this->callAPISuccess("SepaAlternativeBatching", "update", array("type" => "RCUR"));

    // 6) check that there NO new group and no new contribution has been created
    $group_count_after = CRM_Core_DAO::singleValueQuery("SELECT COUNT(id) FROM civicrm_sdd_txgroup;");
    $contribution_count_after = CRM_Core_DAO::singleValueQuery("SELECT COUNT(id) FROM civicrm_contribution;");
    $this->assertEquals($contribution_count_before, $contribution_count_after, "A new contribution has been created, but shouldn't have!");
    $this->assertEquals($group_count_before, $group_count_after, "A new group has been created, but shouldn't have!");
    $this->assertDBQuery(1, "SELECT count(id) FROM civicrm_contribution WHERE contribution_recur_id=$contribution_recur_id;");    
  }  





  // ############################################################################
  //                              Helper functions
  // ############################################################################



  /**
   * create a mandate
   *
   * @author endres -at- systopia.de
   * @return array with mandate data
   */
  function createMandate($mandate_parms = array(), $contrib_parms = array()) {
    // read the payment instrument ids  
    $payment_instrument_FRST = (int) CRM_Core_OptionGroup::getValue('payment_instrument', 'FRST', 'name');
    $this->assertNotEmpty($payment_instrument_FRST, "Could not find the 'FRST' payment instrument.");
    $payment_instrument_RCUR = (int) CRM_Core_OptionGroup::getValue('payment_instrument', 'RCUR', 'name');
    $this->assertNotEmpty($payment_instrument_RCUR, "Could not find the 'RCUR' payment instrument.");
    $contribution_status_pending = (int) CRM_Core_OptionGroup::getValue('contribution_status', 'Pending', 'name');
    $this->assertNotEmpty($contribution_status_pending, "Could not find the 'Pending' contribution status.");

    $mode = empty($mandate_parms['type'])?'OOFF':$mandate_parms['type'];
    $this->assertTrue(($mode=='OOFF' || $mode=='RCUR'), "Mandat can only be of type 'OOFF' or 'RCUR'!");
    $contribution_entity = ($mode=='OOFF')?'Contribution':'ContributionRecur';
    $contribution_table  = ($mode=='OOFF')?'civicrm_contribution':'civicrm_contribution_recur';

    // create a contribution
    $create_contribution = array(
      'contact_id'              => empty($contrib_parms['contact_id'])?$this->individualCreate():$contrib_parms['contact_id'],
      'financial_type_id'       => empty($contrib_parms['financial_type_id'])?1:$contrib_parms['financial_type_id'],
      'currency'                => empty($contrib_parms['currency'])?'EUR':$contrib_parms['currency'],
      'contribution_status_id'  => empty($contrib_parms['contribution_status_id'])?$contribution_status_pending:$contrib_parms['contribution_status_id'],
    );
    if ($mode=='RCUR') {
      $create_contribution['payment_instrument_id'] = $payment_instrument_RCUR;
      $create_contribution['amount'] =
          empty($contrib_parms['amount'])?'6.66':$contrib_parms['amount'];
      $create_contribution['start_date'] =
          empty($contrib_parms['start_date'])?date("Ymd"):$contrib_parms['start_date'];
      $create_contribution['frequency_interval'] =
          empty($contrib_parms['frequency_interval'])?1:$contrib_parms['frequency_interval'];
      $create_contribution['frequency_unit'] =
          empty($contrib_parms['frequency_unit'])?'month':$contrib_parms['frequency_unit'];
      $create_contribution['cycle_day'] =
          empty($contrib_parms['cycle_day'])?date("d", strtotime("+14 days")):$contrib_parms['cycle_day'];
    } else {
      $create_contribution['payment_instrument_id'] = $payment_instrument_FRST;
      $create_contribution['total_amount'] =
          empty($contrib_parms['total_amount'])?'6.66':$contrib_parms['total_amount'];
    }
    $contribution = $this->callAPISuccess($contribution_entity, "create", $create_contribution);


    // create a mandate
    $create_mandate = array(
      "type"          => empty($mandate_parms['type'])?'OOFF':$mandate_parms['type'],
      "status"        => empty($mandate_parms['status'])?'INIT':$mandate_parms['status'],
      "reference"     => empty($mandate_parms['reference'])?md5(microtime()):$mandate_parms['reference'],
      "source"        => empty($mandate_parms['source'])?"TestSource":$mandate_parms['source'],
      "date"          => empty($mandate_parms['date'])?date("YmdHis"):$mandate_parms['date'],
      "creditor_id"   => empty($mandate_parms['creditor_id'])?$this->getCreditor():$mandate_parms['creditor_id'],
      "contact_id"    => empty($mandate_parms['contact_id'])?$create_contribution['contact_id']:$mandate_parms['contact_id'],
      "iban"          => empty($mandate_parms['iban'])?"0000000000000000010001":$mandate_parms['iban'],
      "bic"           => empty($mandate_parms['bic'])?"COLSDE22XXX":$mandate_parms['bic'],
      "creation_date" => empty($mandate_parms['creation_date'])?date("YmdHis"):$mandate_parms['creation_date'],
      "entity_table"  => $contribution_table,
      "entity_id"     => $contribution['id'],
    );
    $mandate = $this->callAPISuccess("SepaMandate", "create", $create_mandate);

    return $mandate['values'][$mandate['id']];
  }

  /**
   * get a creditor. If none exists, create one.
   *
   * @author endres -at- systopia.de
   * @return creditor_id
   */
  function getCreditor() {
    $creditors = $this->callAPISuccess("SepaCreditor", "get", array());
    if ($creditors['count']==0) {
      // none there: create...
      $this->assertDBQuery(NULL, "INSERT INTO `civicrm_tests_dev`.`civicrm_sdd_creditor` (`id`, `creditor_id`, `identifier`, `name`, `address`, `country_id`, `iban`, `bic`, `mandate_prefix`, `payment_processor_id`, `category`, `tag`, `mandate_active`, `sepa_file_format_id`) VALUES ('3', '%1', 'TESTCREDITORID', 'TESTCREDITOR', '104 Wayne Street', '1082', '0000000000000000000000', 'COLSDE22XXX', 'TEST', '0', 'MAIN', NULL, '1', '1');", array(1 => array($this->creditorId, "Int")));
      // and try again
      $creditors = $this->callAPISuccess("SepaCreditor", "get", array());
    }

    // make sure, there is at least one creditor...
    $this->assertGreaterThan(0, $creditors['count'], "Something went wrong, creditor could not be created.");
    
    // return the id of the first entry in the values array
    $first_creditor = reset($creditors['values']);
    return $first_creditor['id'];
  }

  /**
   * get a contact and recurring contribution
   *
   * @author niko bochan
   * @return contactId, contribution
   */
  function createContactAndRecurContrib() {
    // create a contact
    $contactId = $this->individualCreate();
    // create a recurring contribution
    $cparams = array(
      'contact_id' => $contactId,
      'frequency_interval' => '1',
      'frequency_unit' => 'month',
      'amount' => 1337.42,
      'contribution_status_id' => 1,
      'start_date' => date("Ymd"),
      'currency' => "EUR",
      'financial_type_id' => 1,
      'cycle_day' => date("d", strtotime("+14 days")),
    );

    $contrib = $this->callAPISuccess("contribution_recur", "create", $cparams);
    $contrib = $contrib["values"][ $contrib["id"] ];

    $result = array("contactId" => $contactId,
                    "contribution" => $contrib);
    return $result;
  }
}