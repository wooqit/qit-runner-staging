"use strict";

var _interopRequireDefault = require("@babel/runtime/helpers/interopRequireDefault");
Object.defineProperty(exports, "__esModule", {
  value: true
});
exports.WP_API_PATH = exports.WC_API_PATH = exports.WC_ADMIN_API_PATH = void 0;
exports.createClient = createClient;
var _regenerator = _interopRequireDefault(require("@babel/runtime/regenerator"));
var _asyncToGenerator2 = _interopRequireDefault(require("@babel/runtime/helpers/asyncToGenerator"));
var _defineProperty2 = _interopRequireDefault(require("@babel/runtime/helpers/defineProperty"));
var _slicedToArray2 = _interopRequireDefault(require("@babel/runtime/helpers/slicedToArray"));
var _typeof2 = _interopRequireDefault(require("@babel/runtime/helpers/typeof"));
var _axios = _interopRequireDefault(require("axios"));
var _oauth = _interopRequireDefault(require("oauth-1.0a"));
var _crypto = require("crypto");
function ownKeys(e, r) { var t = Object.keys(e); if (Object.getOwnPropertySymbols) { var o = Object.getOwnPropertySymbols(e); r && (o = o.filter(function (r) { return Object.getOwnPropertyDescriptor(e, r).enumerable; })), t.push.apply(t, o); } return t; }
function _objectSpread(e) { for (var r = 1; r < arguments.length; r++) { var t = null != arguments[r] ? arguments[r] : {}; r % 2 ? ownKeys(Object(t), !0).forEach(function (r) { (0, _defineProperty2["default"])(e, r, t[r]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(e, Object.getOwnPropertyDescriptors(t)) : ownKeys(Object(t)).forEach(function (r) { Object.defineProperty(e, r, Object.getOwnPropertyDescriptor(t, r)); }); } return e; } /* eslint-disable jsdoc/check-property-names */ // @ts-check
/**
 * @typedef {Object} BasicAuth
 * @property {'basic'}  type           Type of authentication ('basic')
 * @property {string}   username       Username for basic authentication
 * @property {string}   password       Password for basic authentication
 *
 * @typedef {Object} OAuth1Auth
 * @property {'oauth1'} type           Type of authentication ('oauth1')
 * @property {string}   consumerKey    OAuth1 consumer key
 * @property {string}   consumerSecret OAuth1 consumer secret
 *
 * @typedef {BasicAuth|OAuth1Auth} Auth
 */ /**
 * External dependencies
 */
/**
 * Create an API client instance with the given configuration
 *
 * @param {string} baseURL Base URL for the API
 * @param {Object} auth    Auth object: { type: 'basic', username, password } or { type: 'oauth1', consumerKey, consumerSecret }
 * @return {Object} API client instance with HTTP methods
 */
function createClient(baseURL, auth) {
  if (!auth || (0, _typeof2["default"])(auth) !== 'object') {
    throw new Error('auth parameter is required and must be an object');
  }
  if (auth.type === 'basic') {
    if (!auth.username || !auth.password) {
      throw new Error('Basic auth requires username and password');
    }
  } else if (auth.type === 'oauth1') {
    if (!auth.consumerKey || !auth.consumerSecret) {
      throw new Error('OAuth1 auth requires consumerKey and consumerSecret');
    }
  } else {
    throw new Error('auth.type must be either "basic" or "oauth1"');
  }

  // Ensure baseURL ends with '/'
  if (!baseURL.endsWith('/')) {
    baseURL += '/';
  }

  // Only append 'wp-json/' if not already present
  if (!baseURL.endsWith('wp-json/')) {
    baseURL += 'wp-json/';
  }
  var axiosConfig = {
    baseURL: baseURL,
    headers: {
      'Content-Type': 'application/json'
    }
  };
  var oauth;
  if (auth.type === 'basic') {
    axiosConfig.auth = {
      username: auth.username,
      password: auth.password
    };

    // Warn if Basic Auth is used over HTTP, except for localhost
    var isHttp = baseURL.startsWith('http');
    var isLocalhost = baseURL.startsWith('http://localhost') || baseURL.startsWith('http://127.0.0.1');
    if (isHttp && !isLocalhost) {
      console.warn('Warning: Using Basic Auth over HTTP exposes credentials in plaintext!');
    }
  } else if (auth.type === 'oauth1') {
    oauth = new _oauth["default"]({
      consumer: {
        key: auth.consumerKey,
        secret: auth.consumerSecret
      },
      signature_method: 'HMAC-SHA256',
      hash_function: function hash_function(base, key) {
        return (0, _crypto.createHmac)('sha256', key).update(base).digest('base64');
      }
    });
  }
  var axiosInstance = _axios["default"].create(axiosConfig);

  // Utility to redact sensitive fields from logs
  function redact(obj) {
    var keys = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : ['password', 'token', 'authorization', 'cookie', 'secret'];
    var shouldRedact = process.env.CI === 'true';
    if (!shouldRedact) return obj;
    if (!obj || (0, _typeof2["default"])(obj) !== 'object') return obj;
    return Object.fromEntries(Object.entries(obj).map(function (_ref) {
      var _ref2 = (0, _slicedToArray2["default"])(_ref, 2),
        k = _ref2[0],
        v = _ref2[1];
      return keys.includes(k.toLowerCase()) ? [k, '********'] : [k, (0, _typeof2["default"])(v) === 'object' ? redact(v, keys) : v];
    }));
  }

  // Centralized logging for requests, with redaction and formatting
  function logRequest(label, details) {
    var redacted = Object.fromEntries(Object.entries(details).map(function (_ref3) {
      var _ref4 = (0, _slicedToArray2["default"])(_ref3, 2),
        k = _ref4[0],
        v = _ref4[1];
      return [k, redact(v)];
    }));
    console.log("[".concat(new Date().toISOString(), "] ").concat(label), redacted);
  }
  function oauthRequest(method, path) {
    var _ref5 = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : {},
      _ref5$params = _ref5.params,
      params = _ref5$params === void 0 ? {} : _ref5$params,
      _ref5$data = _ref5.data,
      data = _ref5$data === void 0 ? {} : _ref5$data,
      _ref5$debug = _ref5.debug,
      debug = _ref5$debug === void 0 ? false : _ref5$debug;
    var url = baseURL + path.replace(/^\//, '');
    var requestConfig = {
      method: method
    };
    var oauthParams, headers;
    if (method === 'GET') {
      // For GET, sign the query params and append both params and OAuth params to the URL
      oauthParams = oauth.authorize({
        url: url,
        method: method,
        data: params
      });
      var urlObj = new URL(url);
      Object.entries(_objectSpread(_objectSpread({}, params), oauthParams)).forEach(function (_ref6) {
        var _ref7 = (0, _slicedToArray2["default"])(_ref6, 2),
          key = _ref7[0],
          value = _ref7[1];
        urlObj.searchParams.append(key, value);
      });
      url = urlObj.toString();
      requestConfig = _objectSpread(_objectSpread({}, requestConfig), {}, {
        url: url
      });
    } else {
      // For POST/PUT/DELETE, sign the body if form-encoded, otherwise sign as if body is empty (for JSON)
      var isJson = (axiosConfig.headers['Content-Type'] || '').includes('application/json');
      oauthParams = oauth.authorize({
        url: url,
        method: method,
        data: isJson ? {} : data
      });
      headers = _objectSpread(_objectSpread({}, axiosConfig.headers), oauth.toHeader(oauthParams));
      requestConfig = _objectSpread(_objectSpread({}, requestConfig), {}, {
        url: url,
        headers: headers,
        data: data
      });
    }
    if (debug) {
      logRequest('oauthRequest', {
        method: method,
        url: url,
        params: params,
        data: data,
        headers: headers
      });
    }
    return (0, _axios["default"])(requestConfig);
  }
  return {
    /**
     * Make a GET request
     *
     * @param {string} path   API endpoint path
     * @param {Object} params Query parameters
     * @return {Promise} Promise that resolves to response object
     */
    get: function get(path) {
      var _arguments = arguments;
      return (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee() {
        var params, debug, response;
        return _regenerator["default"].wrap(function _callee$(_context) {
          while (1) switch (_context.prev = _context.next) {
            case 0:
              params = _arguments.length > 1 && _arguments[1] !== undefined ? _arguments[1] : {};
              debug = _arguments.length > 2 && _arguments[2] !== undefined ? _arguments[2] : false;
              if (!(auth.type === 'oauth1')) {
                _context.next = 4;
                break;
              }
              return _context.abrupt("return", oauthRequest('GET', path, {
                params: params,
                debug: debug
              }));
            case 4:
              _context.next = 6;
              return axiosInstance.get(path, {
                params: params
              });
            case 6:
              response = _context.sent;
              if (debug) {
                logRequest('get', {
                  path: path,
                  params: params,
                  status: response === null || response === void 0 ? void 0 : response.status,
                  data: response === null || response === void 0 ? void 0 : response.data
                });
              }
              return _context.abrupt("return", response);
            case 9:
            case "end":
              return _context.stop();
          }
        }, _callee);
      }))();
    },
    /**
     * Make a POST request
     *
     * @param {string} path API endpoint path
     * @param {Object} data Request body data
     * @return {Promise} Promise that resolves to response object
     */
    post: function post(path) {
      var _arguments2 = arguments;
      return (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee2() {
        var data, debug, response;
        return _regenerator["default"].wrap(function _callee2$(_context2) {
          while (1) switch (_context2.prev = _context2.next) {
            case 0:
              data = _arguments2.length > 1 && _arguments2[1] !== undefined ? _arguments2[1] : {};
              debug = _arguments2.length > 2 && _arguments2[2] !== undefined ? _arguments2[2] : false;
              if (!(auth.type === 'oauth1')) {
                _context2.next = 4;
                break;
              }
              return _context2.abrupt("return", oauthRequest('POST', path, {
                data: data,
                debug: debug
              }));
            case 4:
              _context2.next = 6;
              return axiosInstance.post(path, data);
            case 6:
              response = _context2.sent;
              if (debug) {
                logRequest('post', {
                  path: path,
                  data: data,
                  status: response === null || response === void 0 ? void 0 : response.status,
                  response: response === null || response === void 0 ? void 0 : response.data
                });
              }
              return _context2.abrupt("return", response);
            case 9:
            case "end":
              return _context2.stop();
          }
        }, _callee2);
      }))();
    },
    /**
     * Make a PUT request
     *
     * @param {string} path API endpoint path
     * @param {Object} data Request body data
     * @return {Promise} Promise that resolves to response object
     */
    put: function put(path) {
      var _arguments3 = arguments;
      return (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee3() {
        var data, debug, response;
        return _regenerator["default"].wrap(function _callee3$(_context3) {
          while (1) switch (_context3.prev = _context3.next) {
            case 0:
              data = _arguments3.length > 1 && _arguments3[1] !== undefined ? _arguments3[1] : {};
              debug = _arguments3.length > 2 && _arguments3[2] !== undefined ? _arguments3[2] : false;
              if (!(auth.type === 'oauth1')) {
                _context3.next = 4;
                break;
              }
              return _context3.abrupt("return", oauthRequest('PUT', path, {
                data: data,
                debug: debug
              }));
            case 4:
              _context3.next = 6;
              return axiosInstance.put(path, data);
            case 6:
              response = _context3.sent;
              if (debug) {
                logRequest('put', {
                  path: path,
                  data: data,
                  status: response === null || response === void 0 ? void 0 : response.status,
                  response: response === null || response === void 0 ? void 0 : response.data
                });
              }
              return _context3.abrupt("return", response);
            case 9:
            case "end":
              return _context3.stop();
          }
        }, _callee3);
      }))();
    },
    /**
     * Make a DELETE request
     *
     * @param {string} path   API endpoint path
     * @param {Object} params Query parameters or request body
     * @return {Promise} Promise that resolves to response object
     */
    "delete": function _delete(path) {
      var _arguments4 = arguments;
      return (0, _asyncToGenerator2["default"])(/*#__PURE__*/_regenerator["default"].mark(function _callee4() {
        var params, debug, response;
        return _regenerator["default"].wrap(function _callee4$(_context4) {
          while (1) switch (_context4.prev = _context4.next) {
            case 0:
              params = _arguments4.length > 1 && _arguments4[1] !== undefined ? _arguments4[1] : {};
              debug = _arguments4.length > 2 && _arguments4[2] !== undefined ? _arguments4[2] : false;
              if (!(auth.type === 'oauth1')) {
                _context4.next = 4;
                break;
              }
              return _context4.abrupt("return", oauthRequest('DELETE', path, {
                data: params,
                debug: debug
              }));
            case 4:
              _context4.next = 6;
              return axiosInstance["delete"](path, {
                data: params
              });
            case 6:
              response = _context4.sent;
              if (debug) {
                logRequest('delete', {
                  path: path,
                  params: params,
                  status: response === null || response === void 0 ? void 0 : response.status,
                  response: response === null || response === void 0 ? void 0 : response.data
                });
              }
              return _context4.abrupt("return", response);
            case 9:
            case "end":
              return _context4.stop();
          }
        }, _callee4);
      }))();
    }
  };
}
var WC_API_PATH = exports.WC_API_PATH = 'wc/v3';
var WC_ADMIN_API_PATH = exports.WC_ADMIN_API_PATH = 'wc-admin';
var WP_API_PATH = exports.WP_API_PATH = 'wp/v2';