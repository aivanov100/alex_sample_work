<?php

namespace behat\features\bootstrap;
/**
 * Global constants for the test suite.
 */
class TestSuiteConstants
{
  public const IMPLICIT_WAIT = 10000; // milliseconds
  public const WAIT_TIME_SHORTEST = 2;
  public const WAIT_TIME_SHORTER = 5;
  public const WAIT_TIME_SHORT = 10;
  public const WAIT_TIME_MED_LO = 15;
  public const WAIT_TIME_MED = 20;
  public const WAIT_TIME_MED_HI = 25;
  public const WAIT_TIME_LONG = 30;
  public const JS_WAIT_FOR_AJAX = 30;

  public const LOCAL_NATIVE_URL = 'http://ipcedtr.native';
  public const LOCAL_LANDO_URL = 'http://ipcedtr.lndo.site';
  public const GITLAB_URL = 'http://127.0.0.1:8080';
  public const DEV_SITE_URL = 'https://ipcedge-dev.ipcinternal.org';
  public const STG_SITE_URL = 'https://ipcedge-stg.ipcinternal.org';
  public const STBY_SITE_URL = 'https://ipcedge-stby.ipcinternal.org';
  public const PROD_SITE_URL = 'https://education.ipc.org';
  public const ECOM_PROD_URL = 'https://shop.ipc.org';
  public const CMS_PROD_URL = 'https://www.ipc.org';

  public const MOD_SUFFIX = '-MOD';
  public const GLOBAL_PREFIX = 'ATS-';
  public const GLOBAL_DND_PREFIX = 'DND-';

  public const NON_LATIN_CHAR = 'Д';

  public const TEST_PRODUCT_1_PRODUCT_NUMBER = 'A610-EDG-0-0-0-0-0';
  public const TEST_PRODUCT_1_COURSE_TITLE = 'IPC-A-610 for Operators - English, Online Self-paced';
  public const TEST_PRODUCT_1_PATH = '/product/ipc-610-operators';
  public const TEST_PRODUCT_1_COURSE_TITLE_SHORT = 'IPC-A-610 for Operators';
  public const TEST_PRODUCT_1_CART_ITEM_TITLE = 'IPC-A-610 FOR OPERATORS - ENGLISH, ONLINE SELF-PACED';
  public const TEST_PRODUCT_1_LANG = 'English';
  public const TEST_PRODUCT_1_MODALITY = 'Online Self-paced';
  public const TEST_PRODUCT_1_VOUCHER_TITLE = 'IPC-A-610 for Operators - English, Online Self-paced';
  public const TEST_PRODUCT_1_COURSE_LIST_TITLE = 'IPC-A-610 for Operators - English, Online Self-paced';
  public const TEST_PRODUCT_1_PRE_REQ = 'Electronics Assembly for Operators';

  public const TEST_PRODUCT_2_PRODUCT_NUMBER = 'WHA-EDG-0-0-O-0-0';
  public const TEST_PRODUCT_2_COURSE_TITLE = 'Wire Harness Assembly for Operators - English, Online Self-paced';
  public const TEST_PRODUCT_2_PATH = '/product/wire-harness-assembly-operators';
  public const TEST_PRODUCT_2_COURSE_TITLE_SHORT = 'Wire Harness Assembly for Operators';
  public const TEST_PRODUCT_2_CART_ITEM_TITLE = 'WIRE HARNESS ASSEMBLY FOR OPERATORS - ENGLISH, ONLINE SELF-PACED';
  public const TEST_PRODUCT_2_LANG = 'English';
  public const TEST_PRODUCT_2_MODALITY = 'Online Self-paced';
  public const TEST_PRODUCT_2_VOUCHER_TITLE = 'Wire Harness Assembly for Operators - English, Online Self-paced';
  public const TEST_PRODUCT_2_COURSE_LIST_TITLE = 'Electronics Assembly for Operators - English, Online Self-paced';

  public const TEST_PRODUCT_3_PRODUCT_NUMBER = '';
  public const TEST_PRODUCT_3_COURSE_TITLE = 'Electronics Assembly for Engineers - English, Online Self-paced';
  public const TEST_PRODUCT_3_PATH = '/product/electronics-assembly-engineers';
  public const TEST_PRODUCT_3_COURSE_TITLE_SHORT = 'Electronics Assembly for Engineers';
  public const TEST_PRODUCT_3_CART_ITEM_TITLE = 'ELECTRONICS ASSEMBLY FOR ENGINEERS - ENGLISH, ONLINE SELF-PACED';
  public const TEST_PRODUCT_3_LANG = 'English';
  public const TEST_PRODUCT_3_MODALITY = 'Online Self-paced';
  public const TEST_PRODUCT_3_VOUCHER_TITLE = 'Electronics Assembly for Engineers - English, Online Self-paced';
  public const TEST_PRODUCT_3_COURSE_LIST_TITLE = 'Electronics Assembly for Engineers - English, Online Self-paced';

  public const TEST_PRODUCT_5_PRODUCT_NUMBER = '';
  public const TEST_PRODUCT_5_COURSE_TITLE = 'PCB Design for Manufacturability - English, Online Instructor-led';
  public const TEST_PRODUCT_5_PATH = '/product/pcb-design-manufacturability';
  public const TEST_PRODUCT_5_COURSE_TITLE_SHORT = 'PCB Design for Manufacturability';
  public const TEST_PRODUCT_5_CART_ITEM_TITLE = 'PCB DESIGN FOR MANUFACTURABILITY - ENGLISH, ONLINE INSTRUCTOR-LED';
  public const TEST_PRODUCT_5_LANG = 'English';
  public const TEST_PRODUCT_5_MODALITY = 'Online Instructor-led';
  public const TEST_PRODUCT_5_VOUCHER_TITLE = 'PCB Design for Manufacturability - English, Online Instructor-led, Design for Manufacturability';
  public const TEST_PRODUCT_5_COURSE_LIST_TITLE = 'PCB Design for Manufacturability - English, Online Instructor-led, Design for Manufacturability';

  public const TEST_PRODUCT_7_PRODUCT_NUMBER = 'AOT-EDG-0-0-0-0-0';
  public const TEST_PRODUCT_7_COURSE_TITLE = 'Electronics Assembly for Operators - English, Online Self-paced';
  public const TEST_PRODUCT_7_PATH = '/product/electronics-assembly-operators';
  public const TEST_PRODUCT_7_COURSE_TITLE_SHORT = 'Electronics Assembly for Operators';
  public const TEST_PRODUCT_7_CART_ITEM_TITLE = 'ELECTRONICS ASSEMBLY FOR OPERATORS - ENGLISH, ONLINE SELF-PACED';
  public const TEST_PRODUCT_7_LANG = 'English';
  public const TEST_PRODUCT_7_MODALITY = 'Online Self-paced';
  public const TEST_PRODUCT_7_VOUCHER_TITLE = 'Electronics Assembly for Operators - English, Online Self-paced';
  public const TEST_PRODUCT_7_COURSE_LIST_TITLE = 'Electronics Assembly for Operators - English, Online Self-paced';

  public const TEST_PRODUCT_EDIT_CONTENT = 'PCB Design for Manufacturability - English, Online Instructor-led, PCB Design for Manufacturability Class';
  public const TEST_PRODUCT_EDIT_CONTENT_2 = 'Electronics Assembly for Operators - Spanish, Online Self-paced Class';

  public const SPECIAL_USER_1_EMAIL = 'ats_tester@test.com';
  public const SPECIAL_USER_1_FIRST_NAME = 'ATS';
  public const SPECIAL_USER_1_LAST_NAME = 'Tester-One';
  public const SPECIAL_USER_1_DISPLAYED_NAME = 'ATS Tester-One';

  public const SPECIAL_USER_2_EMAIL = 'ats_tester_AAAAD@test.com';
  public const SPECIAL_USER_2_FIRST_NAME = 'ATS';
  public const SPECIAL_USER_2_LAST_NAME = 'Tester';
  public const SPECIAL_USER_2_DISPLAYED_NAME = 'ATS Tester';

  public const SPECIAL_USER_3_PREFIX = 'Mr.';
  public const SPECIAL_USER_3_FIRST_NAME = 'AutomatedTest';
  public const SPECIAL_USER_3_LAST_NAME = 'Three';
  public const SPECIAL_USER_3_COMPANY = 'Lockheed Martin Corporation';
  public const SPECIAL_USER_3_COMPANY_LOCATION = '4000 Memorial Pkwy SW Huntsville, AL 35802-1326 United States';
  public const SPECIAL_USER_3_EMAIL = 'autoTest3@email.com';

  public const TEST_USER_1_EMAIL = 'instructor@ipc.org';
  public const TEST_USER_1_PREFIX = 'Mr.';
  public const TEST_USER_1_FIRST_NAME = 'Instructor';
  public const TEST_USER_1_LAST_NAME = 'Tester';
  public const TEST_USER_1_COMPANY = 'Lockheed Martin Corporation';
  public const TEST_USER_1_COMPANY_LOCATION = '4000 Memorial Pkwy SW, Huntsville, AL 35802-1326, US';

  public const TEST_COMPANY_1_NAME = 'ATS Test Company';
  public const TEST_COMPANY_1_ADDRESS1 = '123 Test Street';
  public const TEST_COMPANY_1_CITY = 'Test City';
  public const TEST_COMPANY_1_STATE = 'Alabama';
  public const TEST_COMPANY_1_ZIP = 35005;

}
