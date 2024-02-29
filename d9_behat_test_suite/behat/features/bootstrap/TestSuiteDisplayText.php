<?php

namespace behat\features\bootstrap;
/**
 * Defines constants for expected display text.
 */
class TestSuiteDisplayText
{
  // Global.
  public const GLB_LINK_LOGOUT = 'Log out';
  public const GLB_LINK_SHOPPING_CART = 'SHOPPING CART';
  public const GLB_LINK_MY_IPC_EDGE = 'My IPC EDGE';

  // Enroll Students modal ("ESM_").
  public const ENRL_SUCCESS_MSG1 = 'Your student(s) have been submitted for enrollment and will receive an email shortly to access their course.';
  public const ENRL_SUCCESS_MSG2 = 'Students have access to the course for 90 days from the date of enrollment';
  public const CHANGES_SAVED_MSG = 'The changes have been saved.';
  public const ESM_EMAIL_ERROR_MSG = 'Enter a valid email address';
  public const ESM_NAME_NLC_ERROR_MSG = 'First Name entry contains non-Latin characters';
  public const ESM_INSTRUCTIONS_STEP_TEXT1 = 'Your CSV must match the format used by our system.';
  public const ESM_INSTRUCTIONS_STEP_TEXT2 = 'If you attempt to add more students than available vouchers, we will only assign the ones first on the list';
  public const ESM_ERROR_MODAL_MSG1 = 'The student information that you entered contains errors. You need to correct the information in order for these students to be added.';
  public const ESM_ERROR_MODAL_MSG2 = 'The email address you have provided appears invalid.';
  public const ESM_OOPS_MODAL = 'The student(s) you are trying to enroll already have an active enrollment in this course and cannot be added.';

  // Menu items.
  public const MENU_TRAINING_PROGRAMS = 'Training Programs';
  public const MENU_TRAINING_PROGRAMS_DESIGN = 'Design Training Programs';
  public const MENU_TRAINING_PROGRAMS_PROFESSIONAL = 'Professional Development Training Programs';
  public const MENU_TRAINING_PROGRAMS_WORKFORCE = 'Workforce Development Training Programs';
  public const MENU_COURSE_CATALOG = 'Course Catalog ';
  public const MENU_CATALOG_ONLINE_SELF = 'Online Self-Paced';
  public const MENU_CATALOG_ONLINE_INSTR = 'Online Instructor-Led';
  public const MENU_CATALOG_FACE_TO_FACE = 'Face-to-Face Instructor-Led';

  public const DASH_PRIMARY_NAV_MY_DASHBOARD = 'MY DASHBOARD';
  public const DASH_PRIMARY_NAV_MY_IPC_PROFILE = 'MY IPC PROFILE';
  public const DASH_PRIMARY_NAV_MY_IPC_EDGE = 'MY IPC EDGE';

  public const DASH_BREADCRUMB_PURCHASES = 'My Account: Purchases';
  public const DASH_BREADCRUMB_MY_COURSES = 'My Account: Training';
  public const DASH_BREADCRUMB_VOUCHERS = 'My Account: Vouchers';

  public const DASH_LEFT_NAV_CERTIFICATES = 'Certificates';
  public const DASH_LEFT_NAV_PURCHASES = 'Purchases';
  public const DASH_LEFT_NAV_MY_COURSES = 'My Courses';
  public const DASH_LEFT_NAV_VOUCHERS = 'Vouchers';
  public const DASH_LEFT_NAV_MY_GRADES = 'My Grades';
  public const DASH_LEFT_NAV_GRADEBOOK = 'Gradebook';

  public const DASH_BUTTON_VIEW_VOUCHERS = 'View Vouchers';
  // "VM" Prefix stands for "Voucher Modal"
  public const VM_TITLE = 'View Vouchers';
  public const VM_AVAILABLE_VOUCHERS_1 = 'Available Vouchers: 1';
  // "ES" stands for "Enroll Student(s)" Form
  public const VM_ES_TITLE = 'Enroll Student(s)';

  public const LINK_STUDENT_TASKS_COURSES = 'Go to My Courses';
  public const LINK_STUDENT_TASKS_CERTS = 'View/Download Certificates';
  public const LINK_STUDENT_TASKS_GRADES = 'View Course Grades';
  public const LINK_STUDENT_TASKS_PURCHASES = 'View Purchases';

  public const LINK_INSTR_ENROLL_STUDENTS = 'Enroll Students';
  public const LINK_INSTR_VIEW_GRADES = "View Students' Grades";
  public const LINK_INSTR_VIEW_PURCHASES = 'View Purchases';
  public const LINK_INSTR_VIEW_CERTS = 'View/Download Certificates';

  public const STATUS_MSG_ACCEPT_T_AND_C = 'Thank you for accepting. You now have access to this site and your dashboard.';

  public const PRODUCT_PAGE_TAB_DESC = 'Description';
  public const PRODUCT_PAGE_TAB_CC = 'Course Content';
  public const PRODUCT_PAGE_TAB_TA = 'Who Should Take This Course';
  public const PRODUCT_PAGE_TAB_TA_OLD = 'Target Audience';
  public const PRODUCT_PAGE_TAB_MOD = 'Modalities';
  public const PRODUCT_PAGE_TAB_LM = 'Learn More';
  public const PRODUCT_PAGE_PREREQS = 'This item has the following prerequisites:';

  public const COURSE_CATALOG_PG_TITLE = 'Education Catalog';

  public const PRIMARY_NAV_WF_TRAINING = 'Workforce Training';
  public const PRIMARY_NAV_ASSEMBLY_TRAINING = 'Assembly Training';
  public const PRIMARY_NAV_PROG_MNGMT = 'Program Management';
  public const PRIMARY_NAV_ENGINEERS = 'Engineers';
  public const PRIMARY_NAV_EL_ASSEMBLY = 'Electronics Assembly';
  public const PRIMARY_NAV_PCB_FABRICATION = 'PCB Fabrication';
  public const PRIMARY_NAV_WEBINARS = 'Webinars';
  public const PRIMARY_NAV_OPERATORS = 'Operators';
  public const PRIMARY_NAV_EL_ASSEMBLY_OPR = 'Electronics Assembly';
  public const PRIMARY_NAV_WH_ASSEMBLY_OPR = 'Wire Harness Assembly';
  public const PRIMARY_NAV_ALL_COURSES = 'All Courses';
  public const PRIMARY_NAV_MEM_COURSES = 'Member Courses';
  public const PRIMARY_NAV_OSP_COURSES = 'Online Self-Paced';

  public const VOUCHER_TRANSFER_WARNING = 'Submission will be permanent and cannot be undone. Please verify that the information below is correct before proceeding.';
  public const USER_UNENROLL_SUCCESS_MSG = 'User has successfully been unenrolled';

}
