<?php

/**
 * @file
 */

require_once 'CRM/Core/Form.php';
use CRM_Iats_ExtensionUtil as E;
/**
 * Form controller class.
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 * A form to generate new one-time charges on an existing recurring schedule.
 */
class CRM_Iats_Form_IATSOneTimeCharge extends CRM_Core_Form {

  /**
   *
   */
  public function getFields() {
    $civicrm_fields = array(
      'firstName' => 'billing_first_name',
      'lastName' => 'billing_last_name',
      'address' => 'street_address',
      'city' => 'city',
      'state' => 'state_province',
      'zipCode' => 'postal_code',
      'creditCardNum' => 'credit_card_number',
      'creditCardExpiry' => 'credit_card_expiry',
      'mop' => 'credit_card_type',
    );
    // When querying using CustomerLink.
    $iats_fields = array(
    // FLN.
      'creditCardCustomerName' => 'CSTN',
      'address' => 'ADD',
      'city' => 'CTY',
      'state' => 'ST',
      'zipCode' => 'ZC',
      'creditCardNum' => 'CCN',
      'creditCardExpiry' => 'EXP',
      'mop' => 'MP',
    );
    $labels = array(
      // 'firstName' => 'First Name',
      // 'lastName' => 'Last Name',.
      'creditCardCustomerName' => 'Name on Card',
      'address' => 'Street Address',
      'city' => 'City',
      'state' => 'State or Province',
      'zipCode' => 'Postal Code or Zip Code',
      'creditCardNum' => 'Credit Card Number',
      'creditCardExpiry' => 'Credit Card Expiry Date',
      'mop' => 'Credit Card Type',
    );
    return array($civicrm_fields, $iats_fields, $labels);
  }

  /**
   *
   */
  protected function getCustomerCodeDetail($params) {
    $credentials = CRM_Iats_iATSServiceRequest::credentials($params['paymentProcessorId'], $params['is_test']);
    $iats_service_params = array('type' => 'customer', 'iats_domain' => $credentials['domain'], 'method' => 'get_customer_code_detail');
    $iats = new CRM_Iats_iATSServiceRequest($iats_service_params);
    // print_r($iats); die();
    $request = array('customerCode' => $params['customerCode']);
    // Make the soap request.
    $response = $iats->request($credentials, $request);
    // note: don't log this to the iats_response table.
    $customer = $iats->result($response, FALSE);
    // print_r($customer); die();
    if (empty($customer['ac1'])) {
      $alert = E::ts('Unable to retrieve card details from iATS.<br />%1', array(1 => $customer['AUTHORIZATIONRESULT']));
      throw new Exception($alert);
    }
    // This is a SimpleXMLElement Object.
    $ac1 = $customer['ac1'];
    $card = get_object_vars($ac1->CC);
    return $customer + $card;
  }

  /**
   *
   */
  protected function processCreditCardCustomer($values) {
    // Generate another (possibly) recurring contribution, matching our recurring template with submitted value.
    $is_recurrence = !empty($values['is_recurrence']);
    $total_amount = $values['amount'];
    $contribution_template = CRM_Iats_Transaction::getContributionTemplate(array('contribution_recur_id' => $values['crid']));
    $contact_id = $values['cid'];
    $hash = md5(uniqid(rand(), TRUE));
    $contribution_recur_id    = $values['crid'];
    $payment_processor_id = $values['paymentProcessorId'];
    $type = _iats_civicrm_is_iats($payment_processor_id);
    $subtype = substr($type, 11);
    // i.e. now.
    $receive_date = date("YmdHis", time());
    $contribution = array(
      'version'        => 3,
      'contact_id'       => $contact_id,
      'receive_date'       => $receive_date,
      'total_amount'       => $total_amount,
      'contribution_recur_id'  => $contribution_recur_id,
      'invoice_id'       => $hash,
      'contribution_status_id' => 2, /* initialize as pending, so we can run completetransaction after taking the money */
      'payment_processor'   => $payment_processor_id,
      'is_test'        => $values['is_test'], /* propagate the is_test value from the form */
    );
    foreach (array('payment_instrument_id', 'currency', 'financial_type_id') as $key) {
      $contribution[$key] = $contribution_template[$key];
    }
    if ($is_recurrence) {
      $contribution['source'] = "iATS Payments $subtype Recurring Contribution (id=$contribution_recur_id)";
      // We'll use the repeattransaction if the total amount is the same
      $original_contribution_id = ($contribution_template['total_amount'] == $total_amount) ? $contribution_template['original_contribution_id'] : NULL;
    }
    else {
      $original_contribution_id = NULL;
      unset($contribution['contribution_recur_id']);
      $contribution['source'] = "iATS Payments $subtype One-Time Contribution (using id=$contribution_recur_id)";
    }
    $contribution['original_contribution_id'] = $original_contribution_id;
    $contribution['is_email_receipt'] = empty($values['is_email_receipt']) ? '0' : '1';
    // get the payment token and processor information for the recurring schedule.
    try {
      $contribution_recur = civicrm_api3('ContributionRecur', 'getsingle',
        array(
          'id' => $contribution_recur_id,
          'return' => array('payment_token_id'),
        )
      );
      if (!empty($contribution_recur['payment_token_id'])) {
        $payment_token = civicrm_api3('PaymentToken', 'getsingle', array('id' => $contribution_recur['payment_token_id']));
      }
    }
    catch (Exception $e) {
      $error = E::ts('Unexpected error getting a payment token for recurring schedule id %1', array(1 => $contribution_recur_id));
      throw new Exception($error);
    }
    if (empty($payment_token['token'])) {
      $error = E::ts('Recur id %1 is missing a payment token.', array(1 => $contribution_recur_id));
      throw new Exception($error);
    }
    try {
      $paymentProcessor = civicrm_api3('PaymentProcessor', 'getsingle', array('id' => $payment_processor_id)); 
    }
    catch (Exception $e) {
      $error = E::ts('Unexpected error getting payment processor information for recurring schedule id %1', array(1 => $contribution_recur_id));
      throw new Exception($error);
    }
    // Now all the hard work in this function, recycled from the original recurring payment job.
    if (empty($paymentProcessor['id']) || empty($payment_token['token'])) {
      $error = E::ts('Unexpected error transacting one-time payment for schedule id %1', array(1 => $contribution_recur_id));
      throw new Exception($error);
    }
    $result = CRM_Iats_Transaction::process_contribution_payment($contribution, $paymentProcessor, $payment_token);
    return $result;
  }

  /**
   *
   */
  public function buildQuickForm() {

    list($civicrm_fields, $iats_fields, $labels) = $this->getFields();
    $this->add('hidden', 'cid');
    $this->add('hidden', 'crid');
    $this->add('hidden', 'customerCode');
    $this->add('hidden', 'paymentProcessorId');
    $this->add('hidden', 'is_test');
    $cid = CRM_Utils_Request::retrieve('cid', 'Integer');
    $crid = CRM_Utils_Request::retrieve('crid', 'Integer');
    $customerCode = CRM_Utils_Request::retrieve('customerCode', 'String');
    $paymentProcessorId = CRM_Utils_Request::retrieve('paymentProcessorId', 'Positive');
    $is_test = CRM_Utils_Request::retrieve('is_test', 'Integer');
    $is_recurrence = CRM_Utils_Request::retrieve('is_recurrence', 'Integer');
    $defaults = array(
      'cid' => $cid,
      'crid' => $crid,
      'customerCode' => $customerCode,
      'paymentProcessorId' => $paymentProcessorId,
      'is_test' => $is_test,
      'is_recurrence' => 1,
    );
    $this->setDefaults($defaults);
    /* always show lots of detail about the card about to be charged or just charged */
    try {
      $customer = $this->getCustomerCodeDetail($defaults);
    }
    catch (Exception $e) {
      CRM_Core_Session::setStatus($e->getMessage(), E::ts('Warning'), 'alert');
      return;
    }
    foreach ($labels as $name => $label) {
      $iats_field = $iats_fields[$name];
      if (is_string($customer[$iats_field])) {
        $this->add('static', $name, $label, $customer[$iats_field]);
      }
    }
    // todo: show past charges/dates ?
    // Add form elements.
    $this->addMoney(
    // Field name.
      'amount',
    // Field label.
      'Amount',
      TRUE, NULL, FALSE
    );
    $this->add(
    // Field type.
      'checkbox',
    // Field name.
      'is_email_receipt',
      E::ts('Automated email receipt for this contribution.')
    );
    $this->add(
    // Field type.
      'checkbox',
    // Field name.
      'is_recurrence',
      E::ts('Create this as a contribution in the recurring series.')
    );
    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => E::ts('Charge this card'),
        'isDefault' => TRUE,
      ),
      array(
        'type' => 'cancel',
        'name' => E::ts('Back'),
      ),
    ));

    // Export form elements.
    $this->assign('elementNames', $this->getRenderableElementNames());
    // If necessary, warn the user about the nature of what they are about to do.
    if (0 !== $is_recurrence) { // this if is not working!
      $message = E::ts('The contribution created by this form will be saved as contribution in the existing recurring series unless you uncheck the corresponding setting.'); // , $type, $options);.
      CRM_Core_Session::setStatus($message, 'One-Time Charge');
    }
    parent::buildQuickForm();
  }

  /**
   *
   */
  public function postProcess() {
    $values = $this->exportValues();
    // print_r($values); die();
    // send charge request to iATS.
    $result = $this->processCreditCardCustomer($values);
    $message = '<pre>' . print_r($result, TRUE). '</pre>';
    // , $type, $options);.
    CRM_Core_Session::setStatus($message, 'Customer Card Charged');
    $return_qs = http_build_query(array('reset' => 1, 'id' => $values['crid'], 'cid' => $values['cid'], 'context' => 'contribution'));
    $this->controller->_destination = CRM_Utils_System::url('civicrm/contact/view/contributionrecur', $return_qs);
    parent::postProcess();
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

}
