"use strict";

var _interopRequireDefault = require("@babel/runtime/helpers/interopRequireDefault");
Object.defineProperty(exports, "__esModule", {
  value: true
});
exports.transformIntoBlocks = exports.publishPage = exports.openEditorSettings = exports.insertBlockByShortcut = exports.insertBlock = exports.goToPostEditor = exports.goToPageEditor = exports.getCanvas = exports.disableWelcomeModal = exports.closeChoosePatternModal = void 0;
var _regenerator = _interopRequireDefault(require("@babel/runtime/regenerator"));
var _asyncToGenerator2 = _interopRequireDefault(require("@babel/runtime/helpers/asyncToGenerator"));
var closeChoosePatternModal = exports.closeChoosePatternModal = /*#__PURE__*/function () {
  var _ref2 = (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee2(_ref) {
    var page, closeModal;
    return _regenerator["default"].wrap(function _callee2$(_context2) {
      while (1) switch (_context2.prev = _context2.next) {
        case 0:
          page = _ref.page;
          closeModal = page.locator('div').filter({
            hasText: 'Choose a pattern'
          }).getByLabel('Close');
          _context2.next = 4;
          return page.addLocatorHandler(closeModal, /*#__PURE__*/(0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee() {
            return _regenerator["default"].wrap(function _callee$(_context) {
              while (1) switch (_context.prev = _context.next) {
                case 0:
                  _context.next = 2;
                  return closeModal.click();
                case 2:
                case "end":
                  return _context.stop();
              }
            }, _callee);
          })));
        case 4:
        case "end":
          return _context2.stop();
      }
    }, _callee2);
  }));
  return function closeChoosePatternModal(_x) {
    return _ref2.apply(this, arguments);
  };
}();
var disableWelcomeModal = exports.disableWelcomeModal = /*#__PURE__*/function () {
  var _ref5 = (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee3(_ref4) {
    var page, isWelcomeGuideActive;
    return _regenerator["default"].wrap(function _callee3$(_context3) {
      while (1) switch (_context3.prev = _context3.next) {
        case 0:
          page = _ref4.page;
          _context3.next = 3;
          return page.waitForLoadState('domcontentloaded');
        case 3:
          _context3.next = 5;
          return page.evaluate(function () {
            return window.wp.data.select('core/edit-post').isFeatureActive('welcomeGuide');
          });
        case 5:
          isWelcomeGuideActive = _context3.sent;
          if (!isWelcomeGuideActive) {
            _context3.next = 9;
            break;
          }
          _context3.next = 9;
          return page.evaluate(function () {
            return window.wp.data.dispatch('core/edit-post').toggleFeature('welcomeGuide');
          });
        case 9:
        case "end":
          return _context3.stop();
      }
    }, _callee3);
  }));
  return function disableWelcomeModal(_x2) {
    return _ref5.apply(this, arguments);
  };
}();
var openEditorSettings = exports.openEditorSettings = /*#__PURE__*/function () {
  var _ref7 = (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee4(_ref6) {
    var page;
    return _regenerator["default"].wrap(function _callee4$(_context4) {
      while (1) switch (_context4.prev = _context4.next) {
        case 0:
          page = _ref6.page;
          _context4.next = 3;
          return page.getByLabel('Editor Settings').isVisible();
        case 3:
          if (!_context4.sent) {
            _context4.next = 7;
            break;
          }
          console.log('Editor Settings is open, skipping action.');
          _context4.next = 9;
          break;
        case 7:
          _context4.next = 9;
          return page.getByLabel('Settings', {
            exact: true
          }).click();
        case 9:
        case "end":
          return _context4.stop();
      }
    }, _callee4);
  }));
  return function openEditorSettings(_x3) {
    return _ref7.apply(this, arguments);
  };
}();

/**
 * Returns the editor canvas frame for Gutenberg interactions.
 *
 * The Gutenberg editor content can be contained within an iframe in some contexts.
 * This helper function returns the content frame of the editor canvas iframe if it exists,
 * or falls back to the main page if the iframe isn't present.
 *
 * @param {import('@playwright/test').Page} page - The Playwright page object
 * @return {Promise<import('@playwright/test').FrameLocator|import('@playwright/test').Page>} The editor canvas frame or the original page
 */
var getCanvas = exports.getCanvas = /*#__PURE__*/function () {
  var _ref8 = (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee5(page) {
    return _regenerator["default"].wrap(function _callee5$(_context5) {
      while (1) switch (_context5.prev = _context5.next) {
        case 0:
          return _context5.abrupt("return", page.locator('iframe[name="editor-canvas"]').contentFrame() || page);
        case 1:
        case "end":
          return _context5.stop();
      }
    }, _callee5);
  }));
  return function getCanvas(_x4) {
    return _ref8.apply(this, arguments);
  };
}();
var goToPageEditor = exports.goToPageEditor = /*#__PURE__*/function () {
  var _ref10 = (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee6(_ref9) {
    var page;
    return _regenerator["default"].wrap(function _callee6$(_context6) {
      while (1) switch (_context6.prev = _context6.next) {
        case 0:
          page = _ref9.page;
          _context6.next = 3;
          return page["goto"]('wp-admin/post-new.php?post_type=page');
        case 3:
          _context6.next = 5;
          return disableWelcomeModal({
            page: page
          });
        case 5:
          _context6.next = 7;
          return closeChoosePatternModal({
            page: page
          });
        case 7:
        case "end":
          return _context6.stop();
      }
    }, _callee6);
  }));
  return function goToPageEditor(_x5) {
    return _ref10.apply(this, arguments);
  };
}();
var goToPostEditor = exports.goToPostEditor = /*#__PURE__*/function () {
  var _ref12 = (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee7(_ref11) {
    var page;
    return _regenerator["default"].wrap(function _callee7$(_context7) {
      while (1) switch (_context7.prev = _context7.next) {
        case 0:
          page = _ref11.page;
          _context7.next = 3;
          return page["goto"]('wp-admin/post-new.php');
        case 3:
          _context7.next = 5;
          return disableWelcomeModal({
            page: page
          });
        case 5:
        case "end":
          return _context7.stop();
      }
    }, _callee7);
  }));
  return function goToPostEditor(_x6) {
    return _ref12.apply(this, arguments);
  };
}();
var insertBlock = exports.insertBlock = /*#__PURE__*/function () {
  var _ref13 = (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee8(page, blockName) {
    var emptyBlock;
    return _regenerator["default"].wrap(function _callee8$(_context8) {
      while (1) switch (_context8.prev = _context8.next) {
        case 0:
          _context8.next = 2;
          return getCanvas(page);
        case 2:
          emptyBlock = _context8.sent.getByLabel('Empty block');
          _context8.next = 5;
          return emptyBlock.isVisible();
        case 5:
          if (!_context8.sent) {
            _context8.next = 8;
            break;
          }
          _context8.next = 8;
          return emptyBlock.click();
        case 8:
          _context8.next = 10;
          return page.getByRole('button', {
            name: /Toggle block inserter|Block Inserter/,
            expanded: false
          }).click();
        case 10:
          _context8.next = 12;
          return page.getByPlaceholder('Search', {
            exact: true
          }).fill(blockName);
        case 12:
          _context8.next = 14;
          return page.getByRole('option', {
            name: blockName,
            exact: true
          }).click();
        case 14:
          _context8.next = 16;
          return page.getByRole('button', {
            name: 'Close block inserter'
          }).click();
        case 16:
        case "end":
          return _context8.stop();
      }
    }, _callee8);
  }));
  return function insertBlock(_x7, _x8) {
    return _ref13.apply(this, arguments);
  };
}();
var insertBlockByShortcut = exports.insertBlockByShortcut = /*#__PURE__*/function () {
  var _ref14 = (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee9(page, blockName) {
    var canvas, emptyBlockField;
    return _regenerator["default"].wrap(function _callee9$(_context9) {
      while (1) switch (_context9.prev = _context9.next) {
        case 0:
          _context9.next = 2;
          return getCanvas(page);
        case 2:
          canvas = _context9.sent;
          emptyBlockField = canvas.getByText('Type / to choose a block').or(canvas.getByRole('document', {
            name: 'Empty block; start writing or type forward slash to choose a block'
          }));
          _context9.next = 6;
          return emptyBlockField.click();
        case 6:
          _context9.next = 8;
          return emptyBlockField.pressSequentially("/".concat(blockName));
        case 8:
          _context9.next = 10;
          return page.getByRole('option', {
            name: blockName,
            exact: true
          }).click();
        case 10:
        case "end":
          return _context9.stop();
      }
    }, _callee9);
  }));
  return function insertBlockByShortcut(_x9, _x10) {
    return _ref14.apply(this, arguments);
  };
}();
var transformIntoBlocks = exports.transformIntoBlocks = /*#__PURE__*/function () {
  var _ref15 = (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee10(page) {
    var canvas;
    return _regenerator["default"].wrap(function _callee10$(_context10) {
      while (1) switch (_context10.prev = _context10.next) {
        case 0:
          _context10.next = 2;
          return getCanvas(page);
        case 2:
          canvas = _context10.sent;
          _context10.next = 5;
          return canvas.getByRole('button').filter({
            hasText: 'Transform into blocks'
          }).click();
        case 5:
        case "end":
          return _context10.stop();
      }
    }, _callee10);
  }));
  return function transformIntoBlocks(_x11) {
    return _ref15.apply(this, arguments);
  };
}();
var publishPage = exports.publishPage = /*#__PURE__*/function () {
  var _ref16 = (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee11(page, pageTitle) {
    var isPost,
      createPageResponse,
      _args11 = arguments;
    return _regenerator["default"].wrap(function _callee11$(_context11) {
      while (1) switch (_context11.prev = _context11.next) {
        case 0:
          isPost = _args11.length > 2 && _args11[2] !== undefined ? _args11[2] : false;
          _context11.next = 3;
          return page.getByRole('button', {
            name: 'Publish',
            exact: true
          }).dispatchEvent('click');
        case 3:
          createPageResponse = page.waitForResponse(function (response) {
            return response.url().includes(isPost ? '/posts/' : '/pages/') && response.ok() && response.request().method() === 'POST' && response.json().then(function (json) {
              return json.title.rendered === pageTitle && json.status === 'publish';
            });
          });
          _context11.next = 6;
          return page.getByRole('region', {
            name: 'Editor publish'
          }).getByRole('button', {
            name: 'Publish',
            exact: true
          }).click();
        case 6:
          _context11.next = 8;
          return createPageResponse;
        case 8:
        case "end":
          return _context11.stop();
      }
    }, _callee11);
  }));
  return function publishPage(_x12, _x13) {
    return _ref16.apply(this, arguments);
  };
}();