"use strict";

var _interopRequireDefault = require("@babel/runtime/helpers/interopRequireDefault");
Object.defineProperty(exports, "__esModule", {
  value: true
});
exports.addAProductToCart = void 0;
exports.addOneOrMoreProductToCart = addOneOrMoreProductToCart;
var _regenerator = _interopRequireDefault(require("@babel/runtime/regenerator"));
var _asyncToGenerator2 = _interopRequireDefault(require("@babel/runtime/helpers/asyncToGenerator"));
/**
 * Adds a specified quantity of a product by ID to the WooCommerce cart.
 *
 * @param {import('playwright').Page} page
 * @param {string}                    productId
 * @param {number}                    [quantity=1]
 */
var addAProductToCart = exports.addAProductToCart = /*#__PURE__*/function () {
  var _ref = (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee(page, productId) {
    var quantity,
      i,
      responsePromise,
      _args = arguments;
    return _regenerator["default"].wrap(function _callee$(_context) {
      while (1) switch (_context.prev = _context.next) {
        case 0:
          quantity = _args.length > 2 && _args[2] !== undefined ? _args[2] : 1;
          i = 0;
        case 2:
          if (!(i < quantity)) {
            _context.next = 13;
            break;
          }
          responsePromise = page.waitForResponse('**/wp-json/wc/store/v1/cart**');
          _context.next = 6;
          return page["goto"]("shop/?add-to-cart=".concat(productId));
        case 6:
          _context.next = 8;
          return responsePromise;
        case 8:
          _context.next = 10;
          return page.getByRole('alert').waitFor({
            state: 'visible'
          });
        case 10:
          i++;
          _context.next = 2;
          break;
        case 13:
        case "end":
          return _context.stop();
      }
    }, _callee);
  }));
  return function addAProductToCart(_x, _x2) {
    return _ref.apply(this, arguments);
  };
}();

/**
 * Util helper made for adding multiple same products to cart
 *
 * @param {import('playwright').Page} page
 * @param {string}                    productName
 * @param {number}                    quantityCount
 */
function addOneOrMoreProductToCart(_x3, _x4) {
  return _addOneOrMoreProductToCart.apply(this, arguments);
}
function _addOneOrMoreProductToCart() {
  _addOneOrMoreProductToCart = (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee2(page, productName) {
    var quantityCount,
      _args2 = arguments;
    return _regenerator["default"].wrap(function _callee2$(_context2) {
      while (1) switch (_context2.prev = _context2.next) {
        case 0:
          quantityCount = _args2.length > 2 && _args2[2] !== undefined ? _args2[2] : 1;
          _context2.next = 3;
          return page["goto"]("product/".concat(productName.replace(/ /gi, '-').toLowerCase()));
        case 3:
          _context2.next = 5;
          return page.getByLabel('Product quantity').fill(quantityCount.toString());
        case 5:
          _context2.next = 7;
          return page.locator('button[name="add-to-cart"]').click();
        case 7:
        case "end":
          return _context2.stop();
      }
    }, _callee2);
  }));
  return _addOneOrMoreProductToCart.apply(this, arguments);
}