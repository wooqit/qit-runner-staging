"use strict";
var __create = Object.create;
var __defProp = Object.defineProperty;
var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
var __getOwnPropNames = Object.getOwnPropertyNames;
var __getProtoOf = Object.getPrototypeOf;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __export = (target, all) => {
  for (var name in all)
    __defProp(target, name, { get: all[name], enumerable: true });
};
var __copyProps = (to, from, except, desc) => {
  if (from && typeof from === "object" || typeof from === "function") {
    for (let key of __getOwnPropNames(from))
      if (!__hasOwnProp.call(to, key) && key !== except)
        __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
  }
  return to;
};
var __toESM = (mod, isNodeMode, target) => (target = mod != null ? __create(__getProtoOf(mod)) : {}, __copyProps(
  // If the importer is in node compatibility mode or this is not an ESM
  // file that has been converted to a CommonJS file using a Babel-
  // compatible transform (i.e. "__esModule" has not been set), then set
  // "default" to the CommonJS "module.exports" for node compatibility.
  isNodeMode || !mod || !mod.__esModule ? __defProp(target, "default", { value: mod, enumerable: true }) : target,
  mod
));
var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);
var api_client_exports = {};
__export(api_client_exports, {
  WC_ADMIN_API_PATH: () => WC_ADMIN_API_PATH,
  WC_API_PATH: () => WC_API_PATH,
  WP_API_PATH: () => WP_API_PATH,
  createClient: () => createClient
});
module.exports = __toCommonJS(api_client_exports);
var import_axios = __toESM(require("axios"));
var import_oauth_1 = __toESM(require("oauth-1.0a"));
var import_crypto = require("crypto");
function createClient(baseURL, auth) {
  if (!auth || typeof auth !== "object") {
    throw new Error("auth parameter is required and must be an object");
  }
  if (auth.type === "basic") {
    if (!auth.username || !auth.password) {
      throw new Error("Basic auth requires username and password");
    }
  } else if (auth.type === "oauth1") {
    if (!auth.consumerKey || !auth.consumerSecret) {
      throw new Error(
        "OAuth1 auth requires consumerKey and consumerSecret"
      );
    }
  } else {
    throw new Error('auth.type must be either "basic" or "oauth1"');
  }
  let normalizedBaseURL = baseURL;
  if (!normalizedBaseURL.endsWith("/")) {
    normalizedBaseURL += "/";
  }
  if (!normalizedBaseURL.endsWith("wp-json/")) {
    normalizedBaseURL += "wp-json/";
  }
  const axiosConfig = {
    baseURL: normalizedBaseURL,
    headers: {
      "Content-Type": "application/json"
    }
  };
  let oauth;
  if (auth.type === "basic") {
    axiosConfig.auth = {
      username: auth.username,
      password: auth.password
    };
    const isHttp = normalizedBaseURL.startsWith("http://");
    const isLocalhost = normalizedBaseURL.startsWith("http://localhost") || normalizedBaseURL.startsWith("http://127.0.0.1");
    if (isHttp && !isLocalhost) {
      console.warn(
        "Warning: Using Basic Auth over HTTP exposes credentials in plaintext!"
      );
    }
  } else if (auth.type === "oauth1") {
    oauth = new import_oauth_1.default({
      consumer: {
        key: auth.consumerKey,
        secret: auth.consumerSecret
      },
      signature_method: "HMAC-SHA256",
      hash_function: (base, key) => {
        return (0, import_crypto.createHmac)("sha256", key).update(base).digest("base64");
      }
    });
  }
  const axiosInstance = import_axios.default.create(axiosConfig);
  function redact(obj, keys = [
    "password",
    "token",
    "authorization",
    "cookie",
    "secret"
  ]) {
    const shouldRedact = process.env.CI === "true";
    if (!shouldRedact) return obj;
    if (!obj || typeof obj !== "object") return obj;
    return Object.fromEntries(
      Object.entries(obj).map(
        ([k, v]) => keys.includes(k.toLowerCase()) ? [k, "********"] : [
          k,
          typeof v === "object" ? redact(v, keys) : v
        ]
      )
    );
  }
  function logRequest(label, details) {
    const redacted = Object.fromEntries(
      Object.entries(details).map(([k, v]) => [
        k,
        redact(v)
      ])
    );
    console.log(`[${(/* @__PURE__ */ new Date()).toISOString()}] ${label}`, redacted);
  }
  function oauthRequest(method, path, { params = {}, data = {}, debug = false } = {}) {
    if (!oauth) {
      throw new Error("OAuth not initialized");
    }
    let url = normalizedBaseURL + path.replace(/^\//, "");
    let requestConfig = { method };
    let oauthParams;
    let headers;
    if (method === "GET") {
      oauthParams = oauth.authorize({
        url,
        method,
        data: params
      });
      const urlObj = new URL(url);
      Object.entries({ ...params, ...oauthParams }).forEach(
        ([key, value]) => {
          urlObj.searchParams.append(key, String(value));
        }
      );
      url = urlObj.toString();
      requestConfig = { ...requestConfig, url };
    } else {
      const contentType = axiosConfig.headers?.["Content-Type"] || "";
      const isJson = contentType.includes("application/json");
      oauthParams = oauth.authorize({
        url,
        method,
        data: isJson ? {} : data
      });
      headers = {
        ...axiosConfig.headers,
        ...oauth.toHeader(oauthParams)
      };
      requestConfig = { ...requestConfig, url, headers, data };
    }
    if (debug) {
      logRequest("oauthRequest", {
        method,
        url,
        params,
        data,
        headers
      });
    }
    return (0, import_axios.default)(requestConfig);
  }
  return {
    /**
     * Make a GET request.
     *
     * @param path   - API endpoint path
     * @param params - Query parameters
     * @param debug  - Enable debug logging
     * @return Promise that resolves to response object
     */
    async get(path, params = {}, debug = false) {
      if (auth.type === "oauth1") {
        return oauthRequest("GET", path, {
          params,
          debug
        });
      }
      const response = await axiosInstance.get(path, { params });
      if (debug) {
        logRequest("get", {
          path,
          params,
          status: response?.status,
          data: response?.data
        });
      }
      return response;
    },
    /**
     * Make a POST request.
     *
     * @param path  - API endpoint path
     * @param data  - Request body data
     * @param debug - Enable debug logging
     * @return Promise that resolves to response object
     */
    async post(path, data = {}, debug = false) {
      if (auth.type === "oauth1") {
        return oauthRequest("POST", path, {
          data,
          debug
        });
      }
      const response = await axiosInstance.post(path, data);
      if (debug) {
        logRequest("post", {
          path,
          data,
          status: response?.status,
          response: response?.data
        });
      }
      return response;
    },
    /**
     * Make a PUT request.
     *
     * @param path  - API endpoint path
     * @param data  - Request body data
     * @param debug - Enable debug logging
     * @return Promise that resolves to response object
     */
    async put(path, data = {}, debug = false) {
      if (auth.type === "oauth1") {
        return oauthRequest("PUT", path, {
          data,
          debug
        });
      }
      const response = await axiosInstance.put(path, data);
      if (debug) {
        logRequest("put", {
          path,
          data,
          status: response?.status,
          response: response?.data
        });
      }
      return response;
    },
    /**
     * Make a DELETE request.
     *
     * @param path   - API endpoint path
     * @param params - Query parameters or request body
     * @param debug  - Enable debug logging
     * @return Promise that resolves to response object
     */
    async delete(path, params = {}, debug = false) {
      if (auth.type === "oauth1") {
        return oauthRequest("DELETE", path, {
          data: params,
          debug
        });
      }
      const response = await axiosInstance.delete(path, {
        data: params
      });
      if (debug) {
        logRequest("delete", {
          path,
          params,
          status: response?.status,
          response: response?.data
        });
      }
      return response;
    }
  };
}
const WC_API_PATH = "wc/v3";
const WC_ADMIN_API_PATH = "wc-admin";
const WP_API_PATH = "wp/v2";
