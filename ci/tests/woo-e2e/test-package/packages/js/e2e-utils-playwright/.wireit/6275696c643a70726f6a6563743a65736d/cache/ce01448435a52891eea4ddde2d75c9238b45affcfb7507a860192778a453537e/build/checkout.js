"use strict";

var _interopRequireDefault = require("@babel/runtime/helpers/interopRequireDefault");
Object.defineProperty(exports, "__esModule", {
  value: true
});
exports.fillBillingCheckoutBlocks = fillBillingCheckoutBlocks;
exports.fillShippingCheckoutBlocks = fillShippingCheckoutBlocks;
var _regenerator = _interopRequireDefault(require("@babel/runtime/regenerator"));
var _asyncToGenerator2 = _interopRequireDefault(require("@babel/runtime/helpers/asyncToGenerator"));
/**
 * Util helper made to fill the Checkout details in the block-based checkout.
 *
 * @param {Object}  page
 * @param {Object}  [details={}]                     - The shipping details object.
 * @param {string}  [details.country='US']           - The first name.
 * @param {string}  [details.firstName='Mister']     - The first name.
 * @param {string}  [details.lastName='Burns']       - The last name.
 * @param {string}  [details.address='156th Street'] - The address.
 * @param {string}  [details.zip='']                 - The ZIP code.
 * @param {string}  [details.city='']                - The city.
 * @param {string}  [details.state='']               - The State.
 * @param {string}  [details.suburb='']              - The Suburb.
 * @param {string}  [details.province='']            - The Province.
 * @param {string}  [details.district='']            - The District.
 * @param {string}  [details.department='']          - The Department.
 * @param {string}  [details.region='']              - The Region.
 * @param {string}  [details.parish='']              - The Parish.
 * @param {string}  [details.county='']              - The Country.
 * @param {string}  [details.prefecture='']          - The Prefecture.
 * @param {string}  [details.municipality='']        - The Municipality.
 * @param {boolean} [details.isPostalCode=false]     - If true, search by 'Postal code' instead of 'Zip Code'.
 */
function fillCheckoutBlocks(_x) {
  return _fillCheckoutBlocks.apply(this, arguments);
}
/**
 * Convenience function to fill Shipping Address fields.
 *
 * @param {Object} page
 * @param {*}      shippingDetails See arguments description for `fillCheckoutBlocks`.
 */
function _fillCheckoutBlocks() {
  _fillCheckoutBlocks = (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee2(page) {
    var details,
      type,
      _details$country,
      country,
      _details$firstName,
      firstName,
      _details$lastName,
      lastName,
      _details$address,
      address,
      _details$zip,
      zip,
      _details$city,
      city,
      _details$state,
      state,
      _details$suburb,
      suburb,
      _details$province,
      province,
      _details$district,
      district,
      _details$department,
      department,
      _details$region,
      region,
      _details$parish,
      parish,
      _details$county,
      county,
      _details$prefecture,
      prefecture,
      _details$municipality,
      municipality,
      _details$phone,
      phone,
      _details$isPostalCode,
      isPostalCode,
      label,
      setDynamicFieldType,
      _setDynamicFieldType,
      stateField,
      _args2 = arguments;
    return _regenerator["default"].wrap(function _callee2$(_context2) {
      while (1) switch (_context2.prev = _context2.next) {
        case 0:
          _setDynamicFieldType = function _setDynamicFieldType3() {
            _setDynamicFieldType = (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee(field, addressElement) {
              var tagName;
              return _regenerator["default"].wrap(function _callee$(_context) {
                while (1) switch (_context.prev = _context.next) {
                  case 0:
                    _context.next = 2;
                    return field.evaluate(function (el) {
                      return el.tagName.toLowerCase();
                    });
                  case 2:
                    tagName = _context.sent;
                    if (!(tagName === 'select')) {
                      _context.next = 8;
                      break;
                    }
                    _context.next = 6;
                    return field.selectOption(addressElement);
                  case 6:
                    _context.next = 10;
                    break;
                  case 8:
                    _context.next = 10;
                    return field.fill(addressElement);
                  case 10:
                  case "end":
                    return _context.stop();
                }
              }, _callee);
            }));
            return _setDynamicFieldType.apply(this, arguments);
          };
          setDynamicFieldType = function _setDynamicFieldType2(_x4, _x5) {
            return _setDynamicFieldType.apply(this, arguments);
          };
          details = _args2.length > 1 && _args2[1] !== undefined ? _args2[1] : {};
          type = _args2.length > 2 && _args2[2] !== undefined ? _args2[2] : 'shipping';
          _details$country = details.country, country = _details$country === void 0 ? '' : _details$country, _details$firstName = details.firstName, firstName = _details$firstName === void 0 ? '' : _details$firstName, _details$lastName = details.lastName, lastName = _details$lastName === void 0 ? '' : _details$lastName, _details$address = details.address, address = _details$address === void 0 ? '' : _details$address, _details$zip = details.zip, zip = _details$zip === void 0 ? '' : _details$zip, _details$city = details.city, city = _details$city === void 0 ? '' : _details$city, _details$state = details.state, state = _details$state === void 0 ? '' : _details$state, _details$suburb = details.suburb, suburb = _details$suburb === void 0 ? '' : _details$suburb, _details$province = details.province, province = _details$province === void 0 ? '' : _details$province, _details$district = details.district, district = _details$district === void 0 ? '' : _details$district, _details$department = details.department, department = _details$department === void 0 ? '' : _details$department, _details$region = details.region, region = _details$region === void 0 ? '' : _details$region, _details$parish = details.parish, parish = _details$parish === void 0 ? '' : _details$parish, _details$county = details.county, county = _details$county === void 0 ? '' : _details$county, _details$prefecture = details.prefecture, prefecture = _details$prefecture === void 0 ? '' : _details$prefecture, _details$municipality = details.municipality, municipality = _details$municipality === void 0 ? '' : _details$municipality, _details$phone = details.phone, phone = _details$phone === void 0 ? '' : _details$phone, _details$isPostalCode = details.isPostalCode, isPostalCode = _details$isPostalCode === void 0 ? false : _details$isPostalCode;
          label = {
            shipping: 'Shipping address',
            billing: 'Billing address'
          };
          _context2.next = 8;
          return page.getByRole('group', {
            name: label[type]
          }).getByLabel('First name').fill(firstName);
        case 8:
          _context2.next = 10;
          return page.getByRole('group', {
            name: label[type]
          }).getByLabel('Last name').fill(lastName);
        case 10:
          _context2.next = 12;
          return page.getByRole('group', {
            name: label[type]
          }).getByLabel('Address', {
            exact: true
          }).fill(address);
        case 12:
          if (!country) {
            _context2.next = 15;
            break;
          }
          _context2.next = 15;
          return page.getByRole('group', {
            name: label[type]
          }).getByLabel('Country').selectOption(country);
        case 15:
          if (!city) {
            _context2.next = 18;
            break;
          }
          _context2.next = 18;
          return page.getByRole('group', {
            name: label[type]
          }).getByLabel('City').fill(city);
        case 18:
          if (!suburb) {
            _context2.next = 21;
            break;
          }
          _context2.next = 21;
          return page.getByRole('group', {
            name: label[type]
          }).getByLabel('Suburb').fill(suburb);
        case 21:
          if (!province) {
            _context2.next = 24;
            break;
          }
          _context2.next = 24;
          return page.getByRole('group', {
            name: label[type]
          }).getByLabel('Province').selectOption(province);
        case 24:
          if (!district) {
            _context2.next = 27;
            break;
          }
          _context2.next = 27;
          return page.getByRole('group', {
            name: label[type]
          }).getByLabel('District').selectOption(district);
        case 27:
          if (!department) {
            _context2.next = 35;
            break;
          }
          _context2.t0 = setDynamicFieldType;
          _context2.next = 31;
          return page.getByRole('group', {
            name: label[type]
          }).getByLabel('Department');
        case 31:
          _context2.t1 = _context2.sent;
          _context2.t2 = department;
          _context2.next = 35;
          return (0, _context2.t0)(_context2.t1, _context2.t2);
        case 35:
          if (!region) {
            _context2.next = 38;
            break;
          }
          _context2.next = 38;
          return page.getByRole('group', {
            name: label[type]
          }).getByLabel('Region', {
            exact: true
          }).selectOption(region);
        case 38:
          if (!parish) {
            _context2.next = 46;
            break;
          }
          _context2.t3 = setDynamicFieldType;
          _context2.next = 42;
          return page.getByRole('group', {
            name: label[type]
          }).getByLabel('Parish', {
            exact: false
          });
        case 42:
          _context2.t4 = _context2.sent;
          _context2.t5 = parish;
          _context2.next = 46;
          return (0, _context2.t3)(_context2.t4, _context2.t5);
        case 46:
          if (!county) {
            _context2.next = 54;
            break;
          }
          _context2.t6 = setDynamicFieldType;
          _context2.next = 50;
          return page.getByRole('group', {
            name: label[type]
          }).getByLabel('County');
        case 50:
          _context2.t7 = _context2.sent;
          _context2.t8 = county;
          _context2.next = 54;
          return (0, _context2.t6)(_context2.t7, _context2.t8);
        case 54:
          if (!prefecture) {
            _context2.next = 57;
            break;
          }
          _context2.next = 57;
          return page.getByRole('group', {
            name: label[type]
          }).getByLabel('Prefecture').selectOption(prefecture);
        case 57:
          if (!municipality) {
            _context2.next = 60;
            break;
          }
          _context2.next = 60;
          return page.getByRole('group', {
            name: label[type]
          }).getByLabel('Municipality').fill(municipality);
        case 60:
          if (!state) {
            _context2.next = 70;
            break;
          }
          _context2.t9 = page.getByRole('group', {
            name: label[type]
          }).getByLabel('State/County', {
            exact: false
          });
          _context2.next = 64;
          return page.getByRole('group', {
            name: label[type]
          }).getByLabel('State');
        case 64:
          _context2.t10 = _context2.sent;
          _context2.next = 67;
          return _context2.t9.or.call(_context2.t9, _context2.t10);
        case 67:
          stateField = _context2.sent;
          _context2.next = 70;
          return setDynamicFieldType(stateField, state);
        case 70:
          if (!zip) {
            _context2.next = 73;
            break;
          }
          _context2.next = 73;
          return page.getByRole('group', {
            name: label[type]
          }).getByLabel(isPostalCode ? 'Postal code' : 'ZIP Code').fill(zip);
        case 73:
          if (!phone) {
            _context2.next = 76;
            break;
          }
          _context2.next = 76;
          return page.getByRole('group', {
            name: label[type]
          }).getByRole('textbox', {
            name: 'Phone'
          }).fill(phone);
        case 76:
        case "end":
          return _context2.stop();
      }
    }, _callee2);
  }));
  return _fillCheckoutBlocks.apply(this, arguments);
}
function fillShippingCheckoutBlocks(_x2) {
  return _fillShippingCheckoutBlocks.apply(this, arguments);
}
/**
 * Convenience function to fill Billing Address fields.
 *
 * @param {Object} page
 * @param {*}      billingDetails See arguments description for `fillCheckoutBlocks`.
 */
function _fillShippingCheckoutBlocks() {
  _fillShippingCheckoutBlocks = (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee3(page) {
    var shippingDetails,
      _args3 = arguments;
    return _regenerator["default"].wrap(function _callee3$(_context3) {
      while (1) switch (_context3.prev = _context3.next) {
        case 0:
          shippingDetails = _args3.length > 1 && _args3[1] !== undefined ? _args3[1] : {};
          _context3.next = 3;
          return fillCheckoutBlocks(page, shippingDetails, 'shipping');
        case 3:
        case "end":
          return _context3.stop();
      }
    }, _callee3);
  }));
  return _fillShippingCheckoutBlocks.apply(this, arguments);
}
function fillBillingCheckoutBlocks(_x3) {
  return _fillBillingCheckoutBlocks.apply(this, arguments);
}
function _fillBillingCheckoutBlocks() {
  _fillBillingCheckoutBlocks = (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee4(page) {
    var billingDetails,
      _args4 = arguments;
    return _regenerator["default"].wrap(function _callee4$(_context4) {
      while (1) switch (_context4.prev = _context4.next) {
        case 0:
          billingDetails = _args4.length > 1 && _args4[1] !== undefined ? _args4[1] : {};
          _context4.next = 3;
          return fillCheckoutBlocks(page, billingDetails, 'billing');
        case 3:
        case "end":
          return _context4.stop();
      }
    }, _callee4);
  }));
  return _fillBillingCheckoutBlocks.apply(this, arguments);
}