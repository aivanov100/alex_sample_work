<?php

namespace behat\features\bootstrap;
/**
 * Defines constants for element locators/selectors.
 */
class TestSuiteLocators
{

  // Global.
  public const STATUS_MESSAGES = '.messages--status';
  public const LINK_MY_IPC_EDGE = 'ul.menu--account li.menu__item--my-account a';
  public const MODAL_DEFAULT_CLOSE = '.ui-modal-default-close-btn';
  public const LOGIN_LINK = '.site-header-top .menu--account .menu__item--log-in a';
  public const LOGOUT_LINK = '.menu__item--log-out a';
  public const SUCCESS_MODAL = '.sucsess';
  public const MODAL_CLOSE_BTN = '.modal-close-btn';
  public const SUCCESS_BOX = '.success-box';
  public const WARNING_DIV = '.warning-info';
  public const REACT_TABLE_ROW = '.rdt_TableRow';
  public const OOPS_MODAL = '.errorModal';

  // Admin Top Menu.
  public const TOP_MENU_IPC_LINK = '.toolbar-menu .menu-item a.toolbar-icon-ipc-menu';
  public const TM_IPC_CUSTOM_LOGS = '.toolbar-menu-administration .menu-item .toolbar-menu .menu-item a[href="/admin/reports/ipc-logs"]';
  public const TM_PEOPLE_LINK = '.toolbar-menu-administration li.menu-item a[href="/admin/people"]';
  public const TM_CONTENT_LINK = '.toolbar-menu-administration li.menu-item a[href="/admin/content"]';

  // Admin Pages.
  public const ADMIN_PEOPLE_TABLE = '.view-user-admin-people .views-form table.views-table';
  public const VIEWS_VIEW_TABLE = '.view-content .views-form table.views-table';
  public const VIEWS_VIEW_ROW = 'tbody tr';
  public const EDIT_PRODUCT_LOCAL_TASKS = 'div.block-local-tasks-block';

  // Admin IPC Logs Page.
  public const ADM_IPC_LOGS_TABLE = '.view-ipc-custom-logs table.views-table';
  public const IPCLOGS_FLD_CHANGED = 'td.views-field-field-updated';
  public const IPCLOGS_FLD_NEW_VAL = 'td.views-field-new-value';
  public const IPCLOGS_FLD_OLD_VAL = 'td.views-field-old-value';
  public const IPCLOGS_FLD_EMAIL = 'td.views-field-email';
  public const IPCLOGS_FLD_UID = 'td.views-field-userId';
  public const IPCLOGS_FLD_ENTITY = 'td.views-field-entity-of-field-updated';

  // View Vouchers modal.
  public const VV_MODAL_INFO_DIV = '.VouchersByCourseTable .voucher-info';
  public const VV_MODAL_STUDENTS_TABLE = '.VouchersByCourseTable .voucher-datatable .rdt_Table';
  public const VV_ACTIONS_EDIT = '.MuiPaper-root ul.MuiList-root li.MuiMenuItem-root[data-value="edit"]';
  public const VV_ACTIONS_DELETE = '.MuiPaper-root ul.MuiList-root li.MuiMenuItem-root[data-value="unenroll"]';
  public const VV_REMOVE_CONFIRM = 'div[aria-labelledby="alert-dialog-title"]';
  public const VV_ENROLL_NOW_BTN = '.enroll-students-list .modalFooter button';

  // Enroll Student(s) modal (ES_MODAL/ESM).
  public const ES_MODAL = '.enroll-modal';
  public const ES_MODAL_INFO_DIV = '.enroll-modal .voucher-info';
  public const ES_MODAL_LANG = '.enroll-modal .lang-dropdown';
  public const ES_MODAL_MODALITY = '.enroll-modal .mod-dropdown';
  public const ESM_COURSE_LIST = '.enroll-modal .course-list';
  public const ESM_UPLOAD_CSV_ID = 'react-tabs-2';
  public const ESM_UPLOAD_CSV_FORM = '.CsvUpload';
  public const ESM_UPLOAD_CSV_STEP = '.step';
  public const ESM_UPLOAD_CSV_STEP_HEADER = 'h2.step-header';
  public const ESM_SELECT_FILE_REGION = '.file-region';
  public const ESM_SELECT_FILE_CSV_INPUT_ID = 'csvFile';
  public const ESM_ENROLL_NOW_BTN = '.enroll-modal .save-button button.primaryBtn';
  public const ESM_ENROLL_NOW_BTN_DIS = '.enroll-modal .save-button button.primaryBtn.Mui-disabled';
  public const ESM_CSV_ADDED_STUDENTS = '.CreatedStudentsTable';
  public const ESM_ERROR_MODAL = '.errorModal .EditableErrorModal';

  // Dashboard Page (prefix: "DASH_PAGE")
  public const DASH_PAGE_MAIN_TABLE = '.content__main-content .block-ipc-ui .data-table .rdt_Table';
  public const DASH_PAGE_V_TABLE = '#user-vouchers-component .UserVouchersList .rdt_Table';
  public const DASH_PAGE_PAGINATION = '.CustomPagination';
  // Dashboard Page (prefix: "DP").
  public const DP_MY_MEMBER_COURSES = '#my-courses-component';
  public const DP_MY_MEMBER_COURSES_ID = 'my-courses-component';
  public const DP_MY_TRAINING_ID = 'my-training-component';
  public const DP_MY_VOUCHERS_LINK = '.menu--class-management .menu__item a[href="/dashboard/my-vouchers"]';
  public const DP_COURSES_TAB = '.courses-tabs ul.react-tabs__tab-list li';
  public const DP_VOUCHERS_LINK = '.site-sidebar--left .ul.menu--dashboard-my-account li.menu__item--vouchers a';

}
