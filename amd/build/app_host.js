"use strict";
var QuizgeistHostApp = (() => {
  var __create = Object.create;
  var __defProp = Object.defineProperty;
  var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __getProtoOf = Object.getPrototypeOf;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __defNormalProp = (obj, key, value2) => key in obj ? __defProp(obj, key, { enumerable: true, configurable: true, writable: true, value: value2 }) : obj[key] = value2;
  var __commonJS = (cb, mod) => function __require() {
    return mod || (0, cb[__getOwnPropNames(cb)[0]])((mod = { exports: {} }).exports, mod), mod.exports;
  };
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
  var __publicField = (obj, key, value2) => __defNormalProp(obj, typeof key !== "symbol" ? key + "" : key, value2);

  // node_modules/qrcode/lib/can-promise.js
  var require_can_promise = __commonJS({
    "node_modules/qrcode/lib/can-promise.js"(exports, module) {
      module.exports = function() {
        return typeof Promise === "function" && Promise.prototype && Promise.prototype.then;
      };
    }
  });

  // node_modules/qrcode/lib/core/utils.js
  var require_utils = __commonJS({
    "node_modules/qrcode/lib/core/utils.js"(exports) {
      var toSJISFunction;
      var CODEWORDS_COUNT = [
        0,
        // Not used
        26,
        44,
        70,
        100,
        134,
        172,
        196,
        242,
        292,
        346,
        404,
        466,
        532,
        581,
        655,
        733,
        815,
        901,
        991,
        1085,
        1156,
        1258,
        1364,
        1474,
        1588,
        1706,
        1828,
        1921,
        2051,
        2185,
        2323,
        2465,
        2611,
        2761,
        2876,
        3034,
        3196,
        3362,
        3532,
        3706
      ];
      exports.getSymbolSize = function getSymbolSize(version) {
        if (!version) throw new Error('"version" cannot be null or undefined');
        if (version < 1 || version > 40) throw new Error('"version" should be in range from 1 to 40');
        return version * 4 + 17;
      };
      exports.getSymbolTotalCodewords = function getSymbolTotalCodewords(version) {
        return CODEWORDS_COUNT[version];
      };
      exports.getBCHDigit = function(data2) {
        let digit = 0;
        while (data2 !== 0) {
          digit++;
          data2 >>>= 1;
        }
        return digit;
      };
      exports.setToSJISFunction = function setToSJISFunction(f) {
        if (typeof f !== "function") {
          throw new Error('"toSJISFunc" is not a valid function.');
        }
        toSJISFunction = f;
      };
      exports.isKanjiModeEnabled = function() {
        return typeof toSJISFunction !== "undefined";
      };
      exports.toSJIS = function toSJIS(kanji) {
        return toSJISFunction(kanji);
      };
    }
  });

  // node_modules/qrcode/lib/core/error-correction-level.js
  var require_error_correction_level = __commonJS({
    "node_modules/qrcode/lib/core/error-correction-level.js"(exports) {
      exports.L = { bit: 1 };
      exports.M = { bit: 0 };
      exports.Q = { bit: 3 };
      exports.H = { bit: 2 };
      function fromString(string) {
        if (typeof string !== "string") {
          throw new Error("Param is not a string");
        }
        const lcStr = string.toLowerCase();
        switch (lcStr) {
          case "l":
          case "low":
            return exports.L;
          case "m":
          case "medium":
            return exports.M;
          case "q":
          case "quartile":
            return exports.Q;
          case "h":
          case "high":
            return exports.H;
          default:
            throw new Error("Unknown EC Level: " + string);
        }
      }
      exports.isValid = function isValid(level) {
        return level && typeof level.bit !== "undefined" && level.bit >= 0 && level.bit < 4;
      };
      exports.from = function from(value2, defaultValue) {
        if (exports.isValid(value2)) {
          return value2;
        }
        try {
          return fromString(value2);
        } catch (e) {
          return defaultValue;
        }
      };
    }
  });

  // node_modules/qrcode/lib/core/bit-buffer.js
  var require_bit_buffer = __commonJS({
    "node_modules/qrcode/lib/core/bit-buffer.js"(exports, module) {
      function BitBuffer() {
        this.buffer = [];
        this.length = 0;
      }
      BitBuffer.prototype = {
        get: function(index) {
          const bufIndex = Math.floor(index / 8);
          return (this.buffer[bufIndex] >>> 7 - index % 8 & 1) === 1;
        },
        put: function(num, length) {
          for (let i = 0; i < length; i++) {
            this.putBit((num >>> length - i - 1 & 1) === 1);
          }
        },
        getLengthInBits: function() {
          return this.length;
        },
        putBit: function(bit) {
          const bufIndex = Math.floor(this.length / 8);
          if (this.buffer.length <= bufIndex) {
            this.buffer.push(0);
          }
          if (bit) {
            this.buffer[bufIndex] |= 128 >>> this.length % 8;
          }
          this.length++;
        }
      };
      module.exports = BitBuffer;
    }
  });

  // node_modules/qrcode/lib/core/bit-matrix.js
  var require_bit_matrix = __commonJS({
    "node_modules/qrcode/lib/core/bit-matrix.js"(exports, module) {
      function BitMatrix(size) {
        if (!size || size < 1) {
          throw new Error("BitMatrix size must be defined and greater than 0");
        }
        this.size = size;
        this.data = new Uint8Array(size * size);
        this.reservedBit = new Uint8Array(size * size);
      }
      BitMatrix.prototype.set = function(row, col, value2, reserved) {
        const index = row * this.size + col;
        this.data[index] = value2;
        if (reserved) this.reservedBit[index] = true;
      };
      BitMatrix.prototype.get = function(row, col) {
        return this.data[row * this.size + col];
      };
      BitMatrix.prototype.xor = function(row, col, value2) {
        this.data[row * this.size + col] ^= value2;
      };
      BitMatrix.prototype.isReserved = function(row, col) {
        return this.reservedBit[row * this.size + col];
      };
      module.exports = BitMatrix;
    }
  });

  // node_modules/qrcode/lib/core/alignment-pattern.js
  var require_alignment_pattern = __commonJS({
    "node_modules/qrcode/lib/core/alignment-pattern.js"(exports) {
      var getSymbolSize = require_utils().getSymbolSize;
      exports.getRowColCoords = function getRowColCoords(version) {
        if (version === 1) return [];
        const posCount = Math.floor(version / 7) + 2;
        const size = getSymbolSize(version);
        const intervals = size === 145 ? 26 : Math.ceil((size - 13) / (2 * posCount - 2)) * 2;
        const positions = [size - 7];
        for (let i = 1; i < posCount - 1; i++) {
          positions[i] = positions[i - 1] - intervals;
        }
        positions.push(6);
        return positions.reverse();
      };
      exports.getPositions = function getPositions(version) {
        const coords = [];
        const pos = exports.getRowColCoords(version);
        const posLength = pos.length;
        for (let i = 0; i < posLength; i++) {
          for (let j = 0; j < posLength; j++) {
            if (i === 0 && j === 0 || // top-left
            i === 0 && j === posLength - 1 || // bottom-left
            i === posLength - 1 && j === 0) {
              continue;
            }
            coords.push([pos[i], pos[j]]);
          }
        }
        return coords;
      };
    }
  });

  // node_modules/qrcode/lib/core/finder-pattern.js
  var require_finder_pattern = __commonJS({
    "node_modules/qrcode/lib/core/finder-pattern.js"(exports) {
      var getSymbolSize = require_utils().getSymbolSize;
      var FINDER_PATTERN_SIZE = 7;
      exports.getPositions = function getPositions(version) {
        const size = getSymbolSize(version);
        return [
          // top-left
          [0, 0],
          // top-right
          [size - FINDER_PATTERN_SIZE, 0],
          // bottom-left
          [0, size - FINDER_PATTERN_SIZE]
        ];
      };
    }
  });

  // node_modules/qrcode/lib/core/mask-pattern.js
  var require_mask_pattern = __commonJS({
    "node_modules/qrcode/lib/core/mask-pattern.js"(exports) {
      exports.Patterns = {
        PATTERN000: 0,
        PATTERN001: 1,
        PATTERN010: 2,
        PATTERN011: 3,
        PATTERN100: 4,
        PATTERN101: 5,
        PATTERN110: 6,
        PATTERN111: 7
      };
      var PenaltyScores = {
        N1: 3,
        N2: 3,
        N3: 40,
        N4: 10
      };
      exports.isValid = function isValid(mask) {
        return mask != null && mask !== "" && !isNaN(mask) && mask >= 0 && mask <= 7;
      };
      exports.from = function from(value2) {
        return exports.isValid(value2) ? parseInt(value2, 10) : void 0;
      };
      exports.getPenaltyN1 = function getPenaltyN1(data2) {
        const size = data2.size;
        let points = 0;
        let sameCountCol = 0;
        let sameCountRow = 0;
        let lastCol = null;
        let lastRow = null;
        for (let row = 0; row < size; row++) {
          sameCountCol = sameCountRow = 0;
          lastCol = lastRow = null;
          for (let col = 0; col < size; col++) {
            let module2 = data2.get(row, col);
            if (module2 === lastCol) {
              sameCountCol++;
            } else {
              if (sameCountCol >= 5) points += PenaltyScores.N1 + (sameCountCol - 5);
              lastCol = module2;
              sameCountCol = 1;
            }
            module2 = data2.get(col, row);
            if (module2 === lastRow) {
              sameCountRow++;
            } else {
              if (sameCountRow >= 5) points += PenaltyScores.N1 + (sameCountRow - 5);
              lastRow = module2;
              sameCountRow = 1;
            }
          }
          if (sameCountCol >= 5) points += PenaltyScores.N1 + (sameCountCol - 5);
          if (sameCountRow >= 5) points += PenaltyScores.N1 + (sameCountRow - 5);
        }
        return points;
      };
      exports.getPenaltyN2 = function getPenaltyN2(data2) {
        const size = data2.size;
        let points = 0;
        for (let row = 0; row < size - 1; row++) {
          for (let col = 0; col < size - 1; col++) {
            const last = data2.get(row, col) + data2.get(row, col + 1) + data2.get(row + 1, col) + data2.get(row + 1, col + 1);
            if (last === 4 || last === 0) points++;
          }
        }
        return points * PenaltyScores.N2;
      };
      exports.getPenaltyN3 = function getPenaltyN3(data2) {
        const size = data2.size;
        let points = 0;
        let bitsCol = 0;
        let bitsRow = 0;
        for (let row = 0; row < size; row++) {
          bitsCol = bitsRow = 0;
          for (let col = 0; col < size; col++) {
            bitsCol = bitsCol << 1 & 2047 | data2.get(row, col);
            if (col >= 10 && (bitsCol === 1488 || bitsCol === 93)) points++;
            bitsRow = bitsRow << 1 & 2047 | data2.get(col, row);
            if (col >= 10 && (bitsRow === 1488 || bitsRow === 93)) points++;
          }
        }
        return points * PenaltyScores.N3;
      };
      exports.getPenaltyN4 = function getPenaltyN4(data2) {
        let darkCount = 0;
        const modulesCount = data2.data.length;
        for (let i = 0; i < modulesCount; i++) darkCount += data2.data[i];
        const k = Math.abs(Math.ceil(darkCount * 100 / modulesCount / 5) - 10);
        return k * PenaltyScores.N4;
      };
      function getMaskAt(maskPattern, i, j) {
        switch (maskPattern) {
          case exports.Patterns.PATTERN000:
            return (i + j) % 2 === 0;
          case exports.Patterns.PATTERN001:
            return i % 2 === 0;
          case exports.Patterns.PATTERN010:
            return j % 3 === 0;
          case exports.Patterns.PATTERN011:
            return (i + j) % 3 === 0;
          case exports.Patterns.PATTERN100:
            return (Math.floor(i / 2) + Math.floor(j / 3)) % 2 === 0;
          case exports.Patterns.PATTERN101:
            return i * j % 2 + i * j % 3 === 0;
          case exports.Patterns.PATTERN110:
            return (i * j % 2 + i * j % 3) % 2 === 0;
          case exports.Patterns.PATTERN111:
            return (i * j % 3 + (i + j) % 2) % 2 === 0;
          default:
            throw new Error("bad maskPattern:" + maskPattern);
        }
      }
      exports.applyMask = function applyMask(pattern, data2) {
        const size = data2.size;
        for (let col = 0; col < size; col++) {
          for (let row = 0; row < size; row++) {
            if (data2.isReserved(row, col)) continue;
            data2.xor(row, col, getMaskAt(pattern, row, col));
          }
        }
      };
      exports.getBestMask = function getBestMask(data2, setupFormatFunc) {
        const numPatterns = Object.keys(exports.Patterns).length;
        let bestPattern = 0;
        let lowerPenalty = Infinity;
        for (let p = 0; p < numPatterns; p++) {
          setupFormatFunc(p);
          exports.applyMask(p, data2);
          const penalty = exports.getPenaltyN1(data2) + exports.getPenaltyN2(data2) + exports.getPenaltyN3(data2) + exports.getPenaltyN4(data2);
          exports.applyMask(p, data2);
          if (penalty < lowerPenalty) {
            lowerPenalty = penalty;
            bestPattern = p;
          }
        }
        return bestPattern;
      };
    }
  });

  // node_modules/qrcode/lib/core/error-correction-code.js
  var require_error_correction_code = __commonJS({
    "node_modules/qrcode/lib/core/error-correction-code.js"(exports) {
      var ECLevel = require_error_correction_level();
      var EC_BLOCKS_TABLE = [
        // L  M  Q  H
        1,
        1,
        1,
        1,
        1,
        1,
        1,
        1,
        1,
        1,
        2,
        2,
        1,
        2,
        2,
        4,
        1,
        2,
        4,
        4,
        2,
        4,
        4,
        4,
        2,
        4,
        6,
        5,
        2,
        4,
        6,
        6,
        2,
        5,
        8,
        8,
        4,
        5,
        8,
        8,
        4,
        5,
        8,
        11,
        4,
        8,
        10,
        11,
        4,
        9,
        12,
        16,
        4,
        9,
        16,
        16,
        6,
        10,
        12,
        18,
        6,
        10,
        17,
        16,
        6,
        11,
        16,
        19,
        6,
        13,
        18,
        21,
        7,
        14,
        21,
        25,
        8,
        16,
        20,
        25,
        8,
        17,
        23,
        25,
        9,
        17,
        23,
        34,
        9,
        18,
        25,
        30,
        10,
        20,
        27,
        32,
        12,
        21,
        29,
        35,
        12,
        23,
        34,
        37,
        12,
        25,
        34,
        40,
        13,
        26,
        35,
        42,
        14,
        28,
        38,
        45,
        15,
        29,
        40,
        48,
        16,
        31,
        43,
        51,
        17,
        33,
        45,
        54,
        18,
        35,
        48,
        57,
        19,
        37,
        51,
        60,
        19,
        38,
        53,
        63,
        20,
        40,
        56,
        66,
        21,
        43,
        59,
        70,
        22,
        45,
        62,
        74,
        24,
        47,
        65,
        77,
        25,
        49,
        68,
        81
      ];
      var EC_CODEWORDS_TABLE = [
        // L  M  Q  H
        7,
        10,
        13,
        17,
        10,
        16,
        22,
        28,
        15,
        26,
        36,
        44,
        20,
        36,
        52,
        64,
        26,
        48,
        72,
        88,
        36,
        64,
        96,
        112,
        40,
        72,
        108,
        130,
        48,
        88,
        132,
        156,
        60,
        110,
        160,
        192,
        72,
        130,
        192,
        224,
        80,
        150,
        224,
        264,
        96,
        176,
        260,
        308,
        104,
        198,
        288,
        352,
        120,
        216,
        320,
        384,
        132,
        240,
        360,
        432,
        144,
        280,
        408,
        480,
        168,
        308,
        448,
        532,
        180,
        338,
        504,
        588,
        196,
        364,
        546,
        650,
        224,
        416,
        600,
        700,
        224,
        442,
        644,
        750,
        252,
        476,
        690,
        816,
        270,
        504,
        750,
        900,
        300,
        560,
        810,
        960,
        312,
        588,
        870,
        1050,
        336,
        644,
        952,
        1110,
        360,
        700,
        1020,
        1200,
        390,
        728,
        1050,
        1260,
        420,
        784,
        1140,
        1350,
        450,
        812,
        1200,
        1440,
        480,
        868,
        1290,
        1530,
        510,
        924,
        1350,
        1620,
        540,
        980,
        1440,
        1710,
        570,
        1036,
        1530,
        1800,
        570,
        1064,
        1590,
        1890,
        600,
        1120,
        1680,
        1980,
        630,
        1204,
        1770,
        2100,
        660,
        1260,
        1860,
        2220,
        720,
        1316,
        1950,
        2310,
        750,
        1372,
        2040,
        2430
      ];
      exports.getBlocksCount = function getBlocksCount(version, errorCorrectionLevel) {
        switch (errorCorrectionLevel) {
          case ECLevel.L:
            return EC_BLOCKS_TABLE[(version - 1) * 4 + 0];
          case ECLevel.M:
            return EC_BLOCKS_TABLE[(version - 1) * 4 + 1];
          case ECLevel.Q:
            return EC_BLOCKS_TABLE[(version - 1) * 4 + 2];
          case ECLevel.H:
            return EC_BLOCKS_TABLE[(version - 1) * 4 + 3];
          default:
            return void 0;
        }
      };
      exports.getTotalCodewordsCount = function getTotalCodewordsCount(version, errorCorrectionLevel) {
        switch (errorCorrectionLevel) {
          case ECLevel.L:
            return EC_CODEWORDS_TABLE[(version - 1) * 4 + 0];
          case ECLevel.M:
            return EC_CODEWORDS_TABLE[(version - 1) * 4 + 1];
          case ECLevel.Q:
            return EC_CODEWORDS_TABLE[(version - 1) * 4 + 2];
          case ECLevel.H:
            return EC_CODEWORDS_TABLE[(version - 1) * 4 + 3];
          default:
            return void 0;
        }
      };
    }
  });

  // node_modules/qrcode/lib/core/galois-field.js
  var require_galois_field = __commonJS({
    "node_modules/qrcode/lib/core/galois-field.js"(exports) {
      var EXP_TABLE = new Uint8Array(512);
      var LOG_TABLE = new Uint8Array(256);
      (function initTables() {
        let x = 1;
        for (let i = 0; i < 255; i++) {
          EXP_TABLE[i] = x;
          LOG_TABLE[x] = i;
          x <<= 1;
          if (x & 256) {
            x ^= 285;
          }
        }
        for (let i = 255; i < 512; i++) {
          EXP_TABLE[i] = EXP_TABLE[i - 255];
        }
      })();
      exports.log = function log(n) {
        if (n < 1) throw new Error("log(" + n + ")");
        return LOG_TABLE[n];
      };
      exports.exp = function exp(n) {
        return EXP_TABLE[n];
      };
      exports.mul = function mul(x, y) {
        if (x === 0 || y === 0) return 0;
        return EXP_TABLE[LOG_TABLE[x] + LOG_TABLE[y]];
      };
    }
  });

  // node_modules/qrcode/lib/core/polynomial.js
  var require_polynomial = __commonJS({
    "node_modules/qrcode/lib/core/polynomial.js"(exports) {
      var GF = require_galois_field();
      exports.mul = function mul(p1, p2) {
        const coeff = new Uint8Array(p1.length + p2.length - 1);
        for (let i = 0; i < p1.length; i++) {
          for (let j = 0; j < p2.length; j++) {
            coeff[i + j] ^= GF.mul(p1[i], p2[j]);
          }
        }
        return coeff;
      };
      exports.mod = function mod(divident, divisor) {
        let result = new Uint8Array(divident);
        while (result.length - divisor.length >= 0) {
          const coeff = result[0];
          for (let i = 0; i < divisor.length; i++) {
            result[i] ^= GF.mul(divisor[i], coeff);
          }
          let offset = 0;
          while (offset < result.length && result[offset] === 0) offset++;
          result = result.slice(offset);
        }
        return result;
      };
      exports.generateECPolynomial = function generateECPolynomial(degree) {
        let poly = new Uint8Array([1]);
        for (let i = 0; i < degree; i++) {
          poly = exports.mul(poly, new Uint8Array([1, GF.exp(i)]));
        }
        return poly;
      };
    }
  });

  // node_modules/qrcode/lib/core/reed-solomon-encoder.js
  var require_reed_solomon_encoder = __commonJS({
    "node_modules/qrcode/lib/core/reed-solomon-encoder.js"(exports, module) {
      var Polynomial = require_polynomial();
      function ReedSolomonEncoder(degree) {
        this.genPoly = void 0;
        this.degree = degree;
        if (this.degree) this.initialize(this.degree);
      }
      ReedSolomonEncoder.prototype.initialize = function initialize(degree) {
        this.degree = degree;
        this.genPoly = Polynomial.generateECPolynomial(this.degree);
      };
      ReedSolomonEncoder.prototype.encode = function encode(data2) {
        if (!this.genPoly) {
          throw new Error("Encoder not initialized");
        }
        const paddedData = new Uint8Array(data2.length + this.degree);
        paddedData.set(data2);
        const remainder = Polynomial.mod(paddedData, this.genPoly);
        const start = this.degree - remainder.length;
        if (start > 0) {
          const buff = new Uint8Array(this.degree);
          buff.set(remainder, start);
          return buff;
        }
        return remainder;
      };
      module.exports = ReedSolomonEncoder;
    }
  });

  // node_modules/qrcode/lib/core/version-check.js
  var require_version_check = __commonJS({
    "node_modules/qrcode/lib/core/version-check.js"(exports) {
      exports.isValid = function isValid(version) {
        return !isNaN(version) && version >= 1 && version <= 40;
      };
    }
  });

  // node_modules/qrcode/lib/core/regex.js
  var require_regex = __commonJS({
    "node_modules/qrcode/lib/core/regex.js"(exports) {
      var numeric = "[0-9]+";
      var alphanumeric = "[A-Z $%*+\\-./:]+";
      var kanji = "(?:[u3000-u303F]|[u3040-u309F]|[u30A0-u30FF]|[uFF00-uFFEF]|[u4E00-u9FAF]|[u2605-u2606]|[u2190-u2195]|u203B|[u2010u2015u2018u2019u2025u2026u201Cu201Du2225u2260]|[u0391-u0451]|[u00A7u00A8u00B1u00B4u00D7u00F7])+";
      kanji = kanji.replace(/u/g, "\\u");
      var byte = "(?:(?![A-Z0-9 $%*+\\-./:]|" + kanji + ")(?:.|[\r\n]))+";
      exports.KANJI = new RegExp(kanji, "g");
      exports.BYTE_KANJI = new RegExp("[^A-Z0-9 $%*+\\-./:]+", "g");
      exports.BYTE = new RegExp(byte, "g");
      exports.NUMERIC = new RegExp(numeric, "g");
      exports.ALPHANUMERIC = new RegExp(alphanumeric, "g");
      var TEST_KANJI = new RegExp("^" + kanji + "$");
      var TEST_NUMERIC = new RegExp("^" + numeric + "$");
      var TEST_ALPHANUMERIC = new RegExp("^[A-Z0-9 $%*+\\-./:]+$");
      exports.testKanji = function testKanji(str) {
        return TEST_KANJI.test(str);
      };
      exports.testNumeric = function testNumeric(str) {
        return TEST_NUMERIC.test(str);
      };
      exports.testAlphanumeric = function testAlphanumeric(str) {
        return TEST_ALPHANUMERIC.test(str);
      };
    }
  });

  // node_modules/qrcode/lib/core/mode.js
  var require_mode = __commonJS({
    "node_modules/qrcode/lib/core/mode.js"(exports) {
      var VersionCheck = require_version_check();
      var Regex = require_regex();
      exports.NUMERIC = {
        id: "Numeric",
        bit: 1 << 0,
        ccBits: [10, 12, 14]
      };
      exports.ALPHANUMERIC = {
        id: "Alphanumeric",
        bit: 1 << 1,
        ccBits: [9, 11, 13]
      };
      exports.BYTE = {
        id: "Byte",
        bit: 1 << 2,
        ccBits: [8, 16, 16]
      };
      exports.KANJI = {
        id: "Kanji",
        bit: 1 << 3,
        ccBits: [8, 10, 12]
      };
      exports.MIXED = {
        bit: -1
      };
      exports.getCharCountIndicator = function getCharCountIndicator(mode, version) {
        if (!mode.ccBits) throw new Error("Invalid mode: " + mode);
        if (!VersionCheck.isValid(version)) {
          throw new Error("Invalid version: " + version);
        }
        if (version >= 1 && version < 10) return mode.ccBits[0];
        else if (version < 27) return mode.ccBits[1];
        return mode.ccBits[2];
      };
      exports.getBestModeForData = function getBestModeForData(dataStr) {
        if (Regex.testNumeric(dataStr)) return exports.NUMERIC;
        else if (Regex.testAlphanumeric(dataStr)) return exports.ALPHANUMERIC;
        else if (Regex.testKanji(dataStr)) return exports.KANJI;
        else return exports.BYTE;
      };
      exports.toString = function toString(mode) {
        if (mode && mode.id) return mode.id;
        throw new Error("Invalid mode");
      };
      exports.isValid = function isValid(mode) {
        return mode && mode.bit && mode.ccBits;
      };
      function fromString(string) {
        if (typeof string !== "string") {
          throw new Error("Param is not a string");
        }
        const lcStr = string.toLowerCase();
        switch (lcStr) {
          case "numeric":
            return exports.NUMERIC;
          case "alphanumeric":
            return exports.ALPHANUMERIC;
          case "kanji":
            return exports.KANJI;
          case "byte":
            return exports.BYTE;
          default:
            throw new Error("Unknown mode: " + string);
        }
      }
      exports.from = function from(value2, defaultValue) {
        if (exports.isValid(value2)) {
          return value2;
        }
        try {
          return fromString(value2);
        } catch (e) {
          return defaultValue;
        }
      };
    }
  });

  // node_modules/qrcode/lib/core/version.js
  var require_version = __commonJS({
    "node_modules/qrcode/lib/core/version.js"(exports) {
      var Utils = require_utils();
      var ECCode = require_error_correction_code();
      var ECLevel = require_error_correction_level();
      var Mode = require_mode();
      var VersionCheck = require_version_check();
      var G18 = 1 << 12 | 1 << 11 | 1 << 10 | 1 << 9 | 1 << 8 | 1 << 5 | 1 << 2 | 1 << 0;
      var G18_BCH = Utils.getBCHDigit(G18);
      function getBestVersionForDataLength(mode, length, errorCorrectionLevel) {
        for (let currentVersion = 1; currentVersion <= 40; currentVersion++) {
          if (length <= exports.getCapacity(currentVersion, errorCorrectionLevel, mode)) {
            return currentVersion;
          }
        }
        return void 0;
      }
      function getReservedBitsCount(mode, version) {
        return Mode.getCharCountIndicator(mode, version) + 4;
      }
      function getTotalBitsFromDataArray(segments, version) {
        let totalBits = 0;
        segments.forEach(function(data2) {
          const reservedBits = getReservedBitsCount(data2.mode, version);
          totalBits += reservedBits + data2.getBitsLength();
        });
        return totalBits;
      }
      function getBestVersionForMixedData(segments, errorCorrectionLevel) {
        for (let currentVersion = 1; currentVersion <= 40; currentVersion++) {
          const length = getTotalBitsFromDataArray(segments, currentVersion);
          if (length <= exports.getCapacity(currentVersion, errorCorrectionLevel, Mode.MIXED)) {
            return currentVersion;
          }
        }
        return void 0;
      }
      exports.from = function from(value2, defaultValue) {
        if (VersionCheck.isValid(value2)) {
          return parseInt(value2, 10);
        }
        return defaultValue;
      };
      exports.getCapacity = function getCapacity(version, errorCorrectionLevel, mode) {
        if (!VersionCheck.isValid(version)) {
          throw new Error("Invalid QR Code version");
        }
        if (typeof mode === "undefined") mode = Mode.BYTE;
        const totalCodewords = Utils.getSymbolTotalCodewords(version);
        const ecTotalCodewords = ECCode.getTotalCodewordsCount(version, errorCorrectionLevel);
        const dataTotalCodewordsBits = (totalCodewords - ecTotalCodewords) * 8;
        if (mode === Mode.MIXED) return dataTotalCodewordsBits;
        const usableBits = dataTotalCodewordsBits - getReservedBitsCount(mode, version);
        switch (mode) {
          case Mode.NUMERIC:
            return Math.floor(usableBits / 10 * 3);
          case Mode.ALPHANUMERIC:
            return Math.floor(usableBits / 11 * 2);
          case Mode.KANJI:
            return Math.floor(usableBits / 13);
          case Mode.BYTE:
          default:
            return Math.floor(usableBits / 8);
        }
      };
      exports.getBestVersionForData = function getBestVersionForData(data2, errorCorrectionLevel) {
        let seg;
        const ecl = ECLevel.from(errorCorrectionLevel, ECLevel.M);
        if (Array.isArray(data2)) {
          if (data2.length > 1) {
            return getBestVersionForMixedData(data2, ecl);
          }
          if (data2.length === 0) {
            return 1;
          }
          seg = data2[0];
        } else {
          seg = data2;
        }
        return getBestVersionForDataLength(seg.mode, seg.getLength(), ecl);
      };
      exports.getEncodedBits = function getEncodedBits(version) {
        if (!VersionCheck.isValid(version) || version < 7) {
          throw new Error("Invalid QR Code version");
        }
        let d = version << 12;
        while (Utils.getBCHDigit(d) - G18_BCH >= 0) {
          d ^= G18 << Utils.getBCHDigit(d) - G18_BCH;
        }
        return version << 12 | d;
      };
    }
  });

  // node_modules/qrcode/lib/core/format-info.js
  var require_format_info = __commonJS({
    "node_modules/qrcode/lib/core/format-info.js"(exports) {
      var Utils = require_utils();
      var G15 = 1 << 10 | 1 << 8 | 1 << 5 | 1 << 4 | 1 << 2 | 1 << 1 | 1 << 0;
      var G15_MASK = 1 << 14 | 1 << 12 | 1 << 10 | 1 << 4 | 1 << 1;
      var G15_BCH = Utils.getBCHDigit(G15);
      exports.getEncodedBits = function getEncodedBits(errorCorrectionLevel, mask) {
        const data2 = errorCorrectionLevel.bit << 3 | mask;
        let d = data2 << 10;
        while (Utils.getBCHDigit(d) - G15_BCH >= 0) {
          d ^= G15 << Utils.getBCHDigit(d) - G15_BCH;
        }
        return (data2 << 10 | d) ^ G15_MASK;
      };
    }
  });

  // node_modules/qrcode/lib/core/numeric-data.js
  var require_numeric_data = __commonJS({
    "node_modules/qrcode/lib/core/numeric-data.js"(exports, module) {
      var Mode = require_mode();
      function NumericData(data2) {
        this.mode = Mode.NUMERIC;
        this.data = data2.toString();
      }
      NumericData.getBitsLength = function getBitsLength(length) {
        return 10 * Math.floor(length / 3) + (length % 3 ? length % 3 * 3 + 1 : 0);
      };
      NumericData.prototype.getLength = function getLength() {
        return this.data.length;
      };
      NumericData.prototype.getBitsLength = function getBitsLength() {
        return NumericData.getBitsLength(this.data.length);
      };
      NumericData.prototype.write = function write(bitBuffer) {
        let i, group, value2;
        for (i = 0; i + 3 <= this.data.length; i += 3) {
          group = this.data.substr(i, 3);
          value2 = parseInt(group, 10);
          bitBuffer.put(value2, 10);
        }
        const remainingNum = this.data.length - i;
        if (remainingNum > 0) {
          group = this.data.substr(i);
          value2 = parseInt(group, 10);
          bitBuffer.put(value2, remainingNum * 3 + 1);
        }
      };
      module.exports = NumericData;
    }
  });

  // node_modules/qrcode/lib/core/alphanumeric-data.js
  var require_alphanumeric_data = __commonJS({
    "node_modules/qrcode/lib/core/alphanumeric-data.js"(exports, module) {
      var Mode = require_mode();
      var ALPHA_NUM_CHARS = [
        "0",
        "1",
        "2",
        "3",
        "4",
        "5",
        "6",
        "7",
        "8",
        "9",
        "A",
        "B",
        "C",
        "D",
        "E",
        "F",
        "G",
        "H",
        "I",
        "J",
        "K",
        "L",
        "M",
        "N",
        "O",
        "P",
        "Q",
        "R",
        "S",
        "T",
        "U",
        "V",
        "W",
        "X",
        "Y",
        "Z",
        " ",
        "$",
        "%",
        "*",
        "+",
        "-",
        ".",
        "/",
        ":"
      ];
      function AlphanumericData(data2) {
        this.mode = Mode.ALPHANUMERIC;
        this.data = data2;
      }
      AlphanumericData.getBitsLength = function getBitsLength(length) {
        return 11 * Math.floor(length / 2) + 6 * (length % 2);
      };
      AlphanumericData.prototype.getLength = function getLength() {
        return this.data.length;
      };
      AlphanumericData.prototype.getBitsLength = function getBitsLength() {
        return AlphanumericData.getBitsLength(this.data.length);
      };
      AlphanumericData.prototype.write = function write(bitBuffer) {
        let i;
        for (i = 0; i + 2 <= this.data.length; i += 2) {
          let value2 = ALPHA_NUM_CHARS.indexOf(this.data[i]) * 45;
          value2 += ALPHA_NUM_CHARS.indexOf(this.data[i + 1]);
          bitBuffer.put(value2, 11);
        }
        if (this.data.length % 2) {
          bitBuffer.put(ALPHA_NUM_CHARS.indexOf(this.data[i]), 6);
        }
      };
      module.exports = AlphanumericData;
    }
  });

  // node_modules/qrcode/lib/core/byte-data.js
  var require_byte_data = __commonJS({
    "node_modules/qrcode/lib/core/byte-data.js"(exports, module) {
      var Mode = require_mode();
      function ByteData(data2) {
        this.mode = Mode.BYTE;
        if (typeof data2 === "string") {
          this.data = new TextEncoder().encode(data2);
        } else {
          this.data = new Uint8Array(data2);
        }
      }
      ByteData.getBitsLength = function getBitsLength(length) {
        return length * 8;
      };
      ByteData.prototype.getLength = function getLength() {
        return this.data.length;
      };
      ByteData.prototype.getBitsLength = function getBitsLength() {
        return ByteData.getBitsLength(this.data.length);
      };
      ByteData.prototype.write = function(bitBuffer) {
        for (let i = 0, l = this.data.length; i < l; i++) {
          bitBuffer.put(this.data[i], 8);
        }
      };
      module.exports = ByteData;
    }
  });

  // node_modules/qrcode/lib/core/kanji-data.js
  var require_kanji_data = __commonJS({
    "node_modules/qrcode/lib/core/kanji-data.js"(exports, module) {
      var Mode = require_mode();
      var Utils = require_utils();
      function KanjiData(data2) {
        this.mode = Mode.KANJI;
        this.data = data2;
      }
      KanjiData.getBitsLength = function getBitsLength(length) {
        return length * 13;
      };
      KanjiData.prototype.getLength = function getLength() {
        return this.data.length;
      };
      KanjiData.prototype.getBitsLength = function getBitsLength() {
        return KanjiData.getBitsLength(this.data.length);
      };
      KanjiData.prototype.write = function(bitBuffer) {
        let i;
        for (i = 0; i < this.data.length; i++) {
          let value2 = Utils.toSJIS(this.data[i]);
          if (value2 >= 33088 && value2 <= 40956) {
            value2 -= 33088;
          } else if (value2 >= 57408 && value2 <= 60351) {
            value2 -= 49472;
          } else {
            throw new Error(
              "Invalid SJIS character: " + this.data[i] + "\nMake sure your charset is UTF-8"
            );
          }
          value2 = (value2 >>> 8 & 255) * 192 + (value2 & 255);
          bitBuffer.put(value2, 13);
        }
      };
      module.exports = KanjiData;
    }
  });

  // node_modules/dijkstrajs/dijkstra.js
  var require_dijkstra = __commonJS({
    "node_modules/dijkstrajs/dijkstra.js"(exports, module) {
      "use strict";
      var dijkstra = {
        single_source_shortest_paths: function(graph, s, d) {
          var predecessors = {};
          var costs = {};
          costs[s] = 0;
          var open = dijkstra.PriorityQueue.make();
          open.push(s, 0);
          var closest, u, v, cost_of_s_to_u, adjacent_nodes, cost_of_e, cost_of_s_to_u_plus_cost_of_e, cost_of_s_to_v, first_visit;
          while (!open.empty()) {
            closest = open.pop();
            u = closest.value;
            cost_of_s_to_u = closest.cost;
            adjacent_nodes = graph[u] || {};
            for (v in adjacent_nodes) {
              if (adjacent_nodes.hasOwnProperty(v)) {
                cost_of_e = adjacent_nodes[v];
                cost_of_s_to_u_plus_cost_of_e = cost_of_s_to_u + cost_of_e;
                cost_of_s_to_v = costs[v];
                first_visit = typeof costs[v] === "undefined";
                if (first_visit || cost_of_s_to_v > cost_of_s_to_u_plus_cost_of_e) {
                  costs[v] = cost_of_s_to_u_plus_cost_of_e;
                  open.push(v, cost_of_s_to_u_plus_cost_of_e);
                  predecessors[v] = u;
                }
              }
            }
          }
          if (typeof d !== "undefined" && typeof costs[d] === "undefined") {
            var msg = ["Could not find a path from ", s, " to ", d, "."].join("");
            throw new Error(msg);
          }
          return predecessors;
        },
        extract_shortest_path_from_predecessor_list: function(predecessors, d) {
          var nodes = [];
          var u = d;
          var predecessor;
          while (u) {
            nodes.push(u);
            predecessor = predecessors[u];
            u = predecessors[u];
          }
          nodes.reverse();
          return nodes;
        },
        find_path: function(graph, s, d) {
          var predecessors = dijkstra.single_source_shortest_paths(graph, s, d);
          return dijkstra.extract_shortest_path_from_predecessor_list(
            predecessors,
            d
          );
        },
        /**
         * A very naive priority queue implementation.
         */
        PriorityQueue: {
          make: function(opts) {
            var T = dijkstra.PriorityQueue, t = {}, key;
            opts = opts || {};
            for (key in T) {
              if (T.hasOwnProperty(key)) {
                t[key] = T[key];
              }
            }
            t.queue = [];
            t.sorter = opts.sorter || T.default_sorter;
            return t;
          },
          default_sorter: function(a, b) {
            return a.cost - b.cost;
          },
          /**
           * Add a new item to the queue and ensure the highest priority element
           * is at the front of the queue.
           */
          push: function(value2, cost) {
            var item = { value: value2, cost };
            this.queue.push(item);
            this.queue.sort(this.sorter);
          },
          /**
           * Return the highest priority element in the queue.
           */
          pop: function() {
            return this.queue.shift();
          },
          empty: function() {
            return this.queue.length === 0;
          }
        }
      };
      if (typeof module !== "undefined") {
        module.exports = dijkstra;
      }
    }
  });

  // node_modules/qrcode/lib/core/segments.js
  var require_segments = __commonJS({
    "node_modules/qrcode/lib/core/segments.js"(exports) {
      var Mode = require_mode();
      var NumericData = require_numeric_data();
      var AlphanumericData = require_alphanumeric_data();
      var ByteData = require_byte_data();
      var KanjiData = require_kanji_data();
      var Regex = require_regex();
      var Utils = require_utils();
      var dijkstra = require_dijkstra();
      function getStringByteLength(str) {
        return unescape(encodeURIComponent(str)).length;
      }
      function getSegments(regex, mode, str) {
        const segments = [];
        let result;
        while ((result = regex.exec(str)) !== null) {
          segments.push({
            data: result[0],
            index: result.index,
            mode,
            length: result[0].length
          });
        }
        return segments;
      }
      function getSegmentsFromString(dataStr) {
        const numSegs = getSegments(Regex.NUMERIC, Mode.NUMERIC, dataStr);
        const alphaNumSegs = getSegments(Regex.ALPHANUMERIC, Mode.ALPHANUMERIC, dataStr);
        let byteSegs;
        let kanjiSegs;
        if (Utils.isKanjiModeEnabled()) {
          byteSegs = getSegments(Regex.BYTE, Mode.BYTE, dataStr);
          kanjiSegs = getSegments(Regex.KANJI, Mode.KANJI, dataStr);
        } else {
          byteSegs = getSegments(Regex.BYTE_KANJI, Mode.BYTE, dataStr);
          kanjiSegs = [];
        }
        const segs = numSegs.concat(alphaNumSegs, byteSegs, kanjiSegs);
        return segs.sort(function(s1, s2) {
          return s1.index - s2.index;
        }).map(function(obj) {
          return {
            data: obj.data,
            mode: obj.mode,
            length: obj.length
          };
        });
      }
      function getSegmentBitsLength(length, mode) {
        switch (mode) {
          case Mode.NUMERIC:
            return NumericData.getBitsLength(length);
          case Mode.ALPHANUMERIC:
            return AlphanumericData.getBitsLength(length);
          case Mode.KANJI:
            return KanjiData.getBitsLength(length);
          case Mode.BYTE:
            return ByteData.getBitsLength(length);
        }
      }
      function mergeSegments(segs) {
        return segs.reduce(function(acc, curr) {
          const prevSeg = acc.length - 1 >= 0 ? acc[acc.length - 1] : null;
          if (prevSeg && prevSeg.mode === curr.mode) {
            acc[acc.length - 1].data += curr.data;
            return acc;
          }
          acc.push(curr);
          return acc;
        }, []);
      }
      function buildNodes(segs) {
        const nodes = [];
        for (let i = 0; i < segs.length; i++) {
          const seg = segs[i];
          switch (seg.mode) {
            case Mode.NUMERIC:
              nodes.push([
                seg,
                { data: seg.data, mode: Mode.ALPHANUMERIC, length: seg.length },
                { data: seg.data, mode: Mode.BYTE, length: seg.length }
              ]);
              break;
            case Mode.ALPHANUMERIC:
              nodes.push([
                seg,
                { data: seg.data, mode: Mode.BYTE, length: seg.length }
              ]);
              break;
            case Mode.KANJI:
              nodes.push([
                seg,
                { data: seg.data, mode: Mode.BYTE, length: getStringByteLength(seg.data) }
              ]);
              break;
            case Mode.BYTE:
              nodes.push([
                { data: seg.data, mode: Mode.BYTE, length: getStringByteLength(seg.data) }
              ]);
          }
        }
        return nodes;
      }
      function buildGraph(nodes, version) {
        const table = {};
        const graph = { start: {} };
        let prevNodeIds = ["start"];
        for (let i = 0; i < nodes.length; i++) {
          const nodeGroup = nodes[i];
          const currentNodeIds = [];
          for (let j = 0; j < nodeGroup.length; j++) {
            const node = nodeGroup[j];
            const key = "" + i + j;
            currentNodeIds.push(key);
            table[key] = { node, lastCount: 0 };
            graph[key] = {};
            for (let n = 0; n < prevNodeIds.length; n++) {
              const prevNodeId = prevNodeIds[n];
              if (table[prevNodeId] && table[prevNodeId].node.mode === node.mode) {
                graph[prevNodeId][key] = getSegmentBitsLength(table[prevNodeId].lastCount + node.length, node.mode) - getSegmentBitsLength(table[prevNodeId].lastCount, node.mode);
                table[prevNodeId].lastCount += node.length;
              } else {
                if (table[prevNodeId]) table[prevNodeId].lastCount = node.length;
                graph[prevNodeId][key] = getSegmentBitsLength(node.length, node.mode) + 4 + Mode.getCharCountIndicator(node.mode, version);
              }
            }
          }
          prevNodeIds = currentNodeIds;
        }
        for (let n = 0; n < prevNodeIds.length; n++) {
          graph[prevNodeIds[n]].end = 0;
        }
        return { map: graph, table };
      }
      function buildSingleSegment(data2, modesHint) {
        let mode;
        const bestMode = Mode.getBestModeForData(data2);
        mode = Mode.from(modesHint, bestMode);
        if (mode !== Mode.BYTE && mode.bit < bestMode.bit) {
          throw new Error('"' + data2 + '" cannot be encoded with mode ' + Mode.toString(mode) + ".\n Suggested mode is: " + Mode.toString(bestMode));
        }
        if (mode === Mode.KANJI && !Utils.isKanjiModeEnabled()) {
          mode = Mode.BYTE;
        }
        switch (mode) {
          case Mode.NUMERIC:
            return new NumericData(data2);
          case Mode.ALPHANUMERIC:
            return new AlphanumericData(data2);
          case Mode.KANJI:
            return new KanjiData(data2);
          case Mode.BYTE:
            return new ByteData(data2);
        }
      }
      exports.fromArray = function fromArray(array) {
        return array.reduce(function(acc, seg) {
          if (typeof seg === "string") {
            acc.push(buildSingleSegment(seg, null));
          } else if (seg.data) {
            acc.push(buildSingleSegment(seg.data, seg.mode));
          }
          return acc;
        }, []);
      };
      exports.fromString = function fromString(data2, version) {
        const segs = getSegmentsFromString(data2, Utils.isKanjiModeEnabled());
        const nodes = buildNodes(segs);
        const graph = buildGraph(nodes, version);
        const path = dijkstra.find_path(graph.map, "start", "end");
        const optimizedSegs = [];
        for (let i = 1; i < path.length - 1; i++) {
          optimizedSegs.push(graph.table[path[i]].node);
        }
        return exports.fromArray(mergeSegments(optimizedSegs));
      };
      exports.rawSplit = function rawSplit(data2) {
        return exports.fromArray(
          getSegmentsFromString(data2, Utils.isKanjiModeEnabled())
        );
      };
    }
  });

  // node_modules/qrcode/lib/core/qrcode.js
  var require_qrcode = __commonJS({
    "node_modules/qrcode/lib/core/qrcode.js"(exports) {
      var Utils = require_utils();
      var ECLevel = require_error_correction_level();
      var BitBuffer = require_bit_buffer();
      var BitMatrix = require_bit_matrix();
      var AlignmentPattern = require_alignment_pattern();
      var FinderPattern = require_finder_pattern();
      var MaskPattern = require_mask_pattern();
      var ECCode = require_error_correction_code();
      var ReedSolomonEncoder = require_reed_solomon_encoder();
      var Version = require_version();
      var FormatInfo = require_format_info();
      var Mode = require_mode();
      var Segments = require_segments();
      function setupFinderPattern(matrix, version) {
        const size = matrix.size;
        const pos = FinderPattern.getPositions(version);
        for (let i = 0; i < pos.length; i++) {
          const row = pos[i][0];
          const col = pos[i][1];
          for (let r = -1; r <= 7; r++) {
            if (row + r <= -1 || size <= row + r) continue;
            for (let c = -1; c <= 7; c++) {
              if (col + c <= -1 || size <= col + c) continue;
              if (r >= 0 && r <= 6 && (c === 0 || c === 6) || c >= 0 && c <= 6 && (r === 0 || r === 6) || r >= 2 && r <= 4 && c >= 2 && c <= 4) {
                matrix.set(row + r, col + c, true, true);
              } else {
                matrix.set(row + r, col + c, false, true);
              }
            }
          }
        }
      }
      function setupTimingPattern(matrix) {
        const size = matrix.size;
        for (let r = 8; r < size - 8; r++) {
          const value2 = r % 2 === 0;
          matrix.set(r, 6, value2, true);
          matrix.set(6, r, value2, true);
        }
      }
      function setupAlignmentPattern(matrix, version) {
        const pos = AlignmentPattern.getPositions(version);
        for (let i = 0; i < pos.length; i++) {
          const row = pos[i][0];
          const col = pos[i][1];
          for (let r = -2; r <= 2; r++) {
            for (let c = -2; c <= 2; c++) {
              if (r === -2 || r === 2 || c === -2 || c === 2 || r === 0 && c === 0) {
                matrix.set(row + r, col + c, true, true);
              } else {
                matrix.set(row + r, col + c, false, true);
              }
            }
          }
        }
      }
      function setupVersionInfo(matrix, version) {
        const size = matrix.size;
        const bits = Version.getEncodedBits(version);
        let row, col, mod;
        for (let i = 0; i < 18; i++) {
          row = Math.floor(i / 3);
          col = i % 3 + size - 8 - 3;
          mod = (bits >> i & 1) === 1;
          matrix.set(row, col, mod, true);
          matrix.set(col, row, mod, true);
        }
      }
      function setupFormatInfo(matrix, errorCorrectionLevel, maskPattern) {
        const size = matrix.size;
        const bits = FormatInfo.getEncodedBits(errorCorrectionLevel, maskPattern);
        let i, mod;
        for (i = 0; i < 15; i++) {
          mod = (bits >> i & 1) === 1;
          if (i < 6) {
            matrix.set(i, 8, mod, true);
          } else if (i < 8) {
            matrix.set(i + 1, 8, mod, true);
          } else {
            matrix.set(size - 15 + i, 8, mod, true);
          }
          if (i < 8) {
            matrix.set(8, size - i - 1, mod, true);
          } else if (i < 9) {
            matrix.set(8, 15 - i - 1 + 1, mod, true);
          } else {
            matrix.set(8, 15 - i - 1, mod, true);
          }
        }
        matrix.set(size - 8, 8, 1, true);
      }
      function setupData(matrix, data2) {
        const size = matrix.size;
        let inc = -1;
        let row = size - 1;
        let bitIndex = 7;
        let byteIndex = 0;
        for (let col = size - 1; col > 0; col -= 2) {
          if (col === 6) col--;
          while (true) {
            for (let c = 0; c < 2; c++) {
              if (!matrix.isReserved(row, col - c)) {
                let dark = false;
                if (byteIndex < data2.length) {
                  dark = (data2[byteIndex] >>> bitIndex & 1) === 1;
                }
                matrix.set(row, col - c, dark);
                bitIndex--;
                if (bitIndex === -1) {
                  byteIndex++;
                  bitIndex = 7;
                }
              }
            }
            row += inc;
            if (row < 0 || size <= row) {
              row -= inc;
              inc = -inc;
              break;
            }
          }
        }
      }
      function createData(version, errorCorrectionLevel, segments) {
        const buffer = new BitBuffer();
        segments.forEach(function(data2) {
          buffer.put(data2.mode.bit, 4);
          buffer.put(data2.getLength(), Mode.getCharCountIndicator(data2.mode, version));
          data2.write(buffer);
        });
        const totalCodewords = Utils.getSymbolTotalCodewords(version);
        const ecTotalCodewords = ECCode.getTotalCodewordsCount(version, errorCorrectionLevel);
        const dataTotalCodewordsBits = (totalCodewords - ecTotalCodewords) * 8;
        if (buffer.getLengthInBits() + 4 <= dataTotalCodewordsBits) {
          buffer.put(0, 4);
        }
        while (buffer.getLengthInBits() % 8 !== 0) {
          buffer.putBit(0);
        }
        const remainingByte = (dataTotalCodewordsBits - buffer.getLengthInBits()) / 8;
        for (let i = 0; i < remainingByte; i++) {
          buffer.put(i % 2 ? 17 : 236, 8);
        }
        return createCodewords(buffer, version, errorCorrectionLevel);
      }
      function createCodewords(bitBuffer, version, errorCorrectionLevel) {
        const totalCodewords = Utils.getSymbolTotalCodewords(version);
        const ecTotalCodewords = ECCode.getTotalCodewordsCount(version, errorCorrectionLevel);
        const dataTotalCodewords = totalCodewords - ecTotalCodewords;
        const ecTotalBlocks = ECCode.getBlocksCount(version, errorCorrectionLevel);
        const blocksInGroup2 = totalCodewords % ecTotalBlocks;
        const blocksInGroup1 = ecTotalBlocks - blocksInGroup2;
        const totalCodewordsInGroup1 = Math.floor(totalCodewords / ecTotalBlocks);
        const dataCodewordsInGroup1 = Math.floor(dataTotalCodewords / ecTotalBlocks);
        const dataCodewordsInGroup2 = dataCodewordsInGroup1 + 1;
        const ecCount = totalCodewordsInGroup1 - dataCodewordsInGroup1;
        const rs = new ReedSolomonEncoder(ecCount);
        let offset = 0;
        const dcData = new Array(ecTotalBlocks);
        const ecData = new Array(ecTotalBlocks);
        let maxDataSize = 0;
        const buffer = new Uint8Array(bitBuffer.buffer);
        for (let b = 0; b < ecTotalBlocks; b++) {
          const dataSize = b < blocksInGroup1 ? dataCodewordsInGroup1 : dataCodewordsInGroup2;
          dcData[b] = buffer.slice(offset, offset + dataSize);
          ecData[b] = rs.encode(dcData[b]);
          offset += dataSize;
          maxDataSize = Math.max(maxDataSize, dataSize);
        }
        const data2 = new Uint8Array(totalCodewords);
        let index = 0;
        let i, r;
        for (i = 0; i < maxDataSize; i++) {
          for (r = 0; r < ecTotalBlocks; r++) {
            if (i < dcData[r].length) {
              data2[index++] = dcData[r][i];
            }
          }
        }
        for (i = 0; i < ecCount; i++) {
          for (r = 0; r < ecTotalBlocks; r++) {
            data2[index++] = ecData[r][i];
          }
        }
        return data2;
      }
      function createSymbol(data2, version, errorCorrectionLevel, maskPattern) {
        let segments;
        if (Array.isArray(data2)) {
          segments = Segments.fromArray(data2);
        } else if (typeof data2 === "string") {
          let estimatedVersion = version;
          if (!estimatedVersion) {
            const rawSegments = Segments.rawSplit(data2);
            estimatedVersion = Version.getBestVersionForData(rawSegments, errorCorrectionLevel);
          }
          segments = Segments.fromString(data2, estimatedVersion || 40);
        } else {
          throw new Error("Invalid data");
        }
        const bestVersion = Version.getBestVersionForData(segments, errorCorrectionLevel);
        if (!bestVersion) {
          throw new Error("The amount of data is too big to be stored in a QR Code");
        }
        if (!version) {
          version = bestVersion;
        } else if (version < bestVersion) {
          throw new Error(
            "\nThe chosen QR Code version cannot contain this amount of data.\nMinimum version required to store current data is: " + bestVersion + ".\n"
          );
        }
        const dataBits = createData(version, errorCorrectionLevel, segments);
        const moduleCount = Utils.getSymbolSize(version);
        const modules = new BitMatrix(moduleCount);
        setupFinderPattern(modules, version);
        setupTimingPattern(modules);
        setupAlignmentPattern(modules, version);
        setupFormatInfo(modules, errorCorrectionLevel, 0);
        if (version >= 7) {
          setupVersionInfo(modules, version);
        }
        setupData(modules, dataBits);
        if (isNaN(maskPattern)) {
          maskPattern = MaskPattern.getBestMask(
            modules,
            setupFormatInfo.bind(null, modules, errorCorrectionLevel)
          );
        }
        MaskPattern.applyMask(maskPattern, modules);
        setupFormatInfo(modules, errorCorrectionLevel, maskPattern);
        return {
          modules,
          version,
          errorCorrectionLevel,
          maskPattern,
          segments
        };
      }
      exports.create = function create(data2, options) {
        if (typeof data2 === "undefined" || data2 === "") {
          throw new Error("No input text");
        }
        let errorCorrectionLevel = ECLevel.M;
        let version;
        let mask;
        if (typeof options !== "undefined") {
          errorCorrectionLevel = ECLevel.from(options.errorCorrectionLevel, ECLevel.M);
          version = Version.from(options.version);
          mask = MaskPattern.from(options.maskPattern);
          if (options.toSJISFunc) {
            Utils.setToSJISFunction(options.toSJISFunc);
          }
        }
        return createSymbol(data2, version, errorCorrectionLevel, mask);
      };
    }
  });

  // node_modules/qrcode/lib/renderer/utils.js
  var require_utils2 = __commonJS({
    "node_modules/qrcode/lib/renderer/utils.js"(exports) {
      function hex2rgba(hex) {
        if (typeof hex === "number") {
          hex = hex.toString();
        }
        if (typeof hex !== "string") {
          throw new Error("Color should be defined as hex string");
        }
        let hexCode = hex.slice().replace("#", "").split("");
        if (hexCode.length < 3 || hexCode.length === 5 || hexCode.length > 8) {
          throw new Error("Invalid hex color: " + hex);
        }
        if (hexCode.length === 3 || hexCode.length === 4) {
          hexCode = Array.prototype.concat.apply([], hexCode.map(function(c) {
            return [c, c];
          }));
        }
        if (hexCode.length === 6) hexCode.push("F", "F");
        const hexValue = parseInt(hexCode.join(""), 16);
        return {
          r: hexValue >> 24 & 255,
          g: hexValue >> 16 & 255,
          b: hexValue >> 8 & 255,
          a: hexValue & 255,
          hex: "#" + hexCode.slice(0, 6).join("")
        };
      }
      exports.getOptions = function getOptions(options) {
        if (!options) options = {};
        if (!options.color) options.color = {};
        const margin = typeof options.margin === "undefined" || options.margin === null || options.margin < 0 ? 4 : options.margin;
        const width = options.width && options.width >= 21 ? options.width : void 0;
        const scale = options.scale || 4;
        return {
          width,
          scale: width ? 4 : scale,
          margin,
          color: {
            dark: hex2rgba(options.color.dark || "#000000ff"),
            light: hex2rgba(options.color.light || "#ffffffff")
          },
          type: options.type,
          rendererOpts: options.rendererOpts || {}
        };
      };
      exports.getScale = function getScale(qrSize, opts) {
        return opts.width && opts.width >= qrSize + opts.margin * 2 ? opts.width / (qrSize + opts.margin * 2) : opts.scale;
      };
      exports.getImageWidth = function getImageWidth(qrSize, opts) {
        const scale = exports.getScale(qrSize, opts);
        return Math.floor((qrSize + opts.margin * 2) * scale);
      };
      exports.qrToImageData = function qrToImageData(imgData, qr, opts) {
        const size = qr.modules.size;
        const data2 = qr.modules.data;
        const scale = exports.getScale(size, opts);
        const symbolSize = Math.floor((size + opts.margin * 2) * scale);
        const scaledMargin = opts.margin * scale;
        const palette = [opts.color.light, opts.color.dark];
        for (let i = 0; i < symbolSize; i++) {
          for (let j = 0; j < symbolSize; j++) {
            let posDst = (i * symbolSize + j) * 4;
            let pxColor = opts.color.light;
            if (i >= scaledMargin && j >= scaledMargin && i < symbolSize - scaledMargin && j < symbolSize - scaledMargin) {
              const iSrc = Math.floor((i - scaledMargin) / scale);
              const jSrc = Math.floor((j - scaledMargin) / scale);
              pxColor = palette[data2[iSrc * size + jSrc] ? 1 : 0];
            }
            imgData[posDst++] = pxColor.r;
            imgData[posDst++] = pxColor.g;
            imgData[posDst++] = pxColor.b;
            imgData[posDst] = pxColor.a;
          }
        }
      };
    }
  });

  // node_modules/qrcode/lib/renderer/canvas.js
  var require_canvas = __commonJS({
    "node_modules/qrcode/lib/renderer/canvas.js"(exports) {
      var Utils = require_utils2();
      function clearCanvas(ctx, canvas, size) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        if (!canvas.style) canvas.style = {};
        canvas.height = size;
        canvas.width = size;
        canvas.style.height = size + "px";
        canvas.style.width = size + "px";
      }
      function getCanvasElement() {
        try {
          return document.createElement("canvas");
        } catch (e) {
          throw new Error("You need to specify a canvas element");
        }
      }
      exports.render = function render(qrData, canvas, options) {
        let opts = options;
        let canvasEl = canvas;
        if (typeof opts === "undefined" && (!canvas || !canvas.getContext)) {
          opts = canvas;
          canvas = void 0;
        }
        if (!canvas) {
          canvasEl = getCanvasElement();
        }
        opts = Utils.getOptions(opts);
        const size = Utils.getImageWidth(qrData.modules.size, opts);
        const ctx = canvasEl.getContext("2d");
        const image = ctx.createImageData(size, size);
        Utils.qrToImageData(image.data, qrData, opts);
        clearCanvas(ctx, canvasEl, size);
        ctx.putImageData(image, 0, 0);
        return canvasEl;
      };
      exports.renderToDataURL = function renderToDataURL(qrData, canvas, options) {
        let opts = options;
        if (typeof opts === "undefined" && (!canvas || !canvas.getContext)) {
          opts = canvas;
          canvas = void 0;
        }
        if (!opts) opts = {};
        const canvasEl = exports.render(qrData, canvas, opts);
        const type = opts.type || "image/png";
        const rendererOpts = opts.rendererOpts || {};
        return canvasEl.toDataURL(type, rendererOpts.quality);
      };
    }
  });

  // node_modules/qrcode/lib/renderer/svg-tag.js
  var require_svg_tag = __commonJS({
    "node_modules/qrcode/lib/renderer/svg-tag.js"(exports) {
      var Utils = require_utils2();
      function getColorAttrib(color, attrib) {
        const alpha = color.a / 255;
        const str = attrib + '="' + color.hex + '"';
        return alpha < 1 ? str + " " + attrib + '-opacity="' + alpha.toFixed(2).slice(1) + '"' : str;
      }
      function svgCmd(cmd, x, y) {
        let str = cmd + x;
        if (typeof y !== "undefined") str += " " + y;
        return str;
      }
      function qrToPath(data2, size, margin) {
        let path = "";
        let moveBy = 0;
        let newRow = false;
        let lineLength = 0;
        for (let i = 0; i < data2.length; i++) {
          const col = Math.floor(i % size);
          const row = Math.floor(i / size);
          if (!col && !newRow) newRow = true;
          if (data2[i]) {
            lineLength++;
            if (!(i > 0 && col > 0 && data2[i - 1])) {
              path += newRow ? svgCmd("M", col + margin, 0.5 + row + margin) : svgCmd("m", moveBy, 0);
              moveBy = 0;
              newRow = false;
            }
            if (!(col + 1 < size && data2[i + 1])) {
              path += svgCmd("h", lineLength);
              lineLength = 0;
            }
          } else {
            moveBy++;
          }
        }
        return path;
      }
      exports.render = function render(qrData, options, cb) {
        const opts = Utils.getOptions(options);
        const size = qrData.modules.size;
        const data2 = qrData.modules.data;
        const qrcodesize = size + opts.margin * 2;
        const bg = !opts.color.light.a ? "" : "<path " + getColorAttrib(opts.color.light, "fill") + ' d="M0 0h' + qrcodesize + "v" + qrcodesize + 'H0z"/>';
        const path = "<path " + getColorAttrib(opts.color.dark, "stroke") + ' d="' + qrToPath(data2, size, opts.margin) + '"/>';
        const viewBox = 'viewBox="0 0 ' + qrcodesize + " " + qrcodesize + '"';
        const width = !opts.width ? "" : 'width="' + opts.width + '" height="' + opts.width + '" ';
        const svgTag = '<svg xmlns="http://www.w3.org/2000/svg" ' + width + viewBox + ' shape-rendering="crispEdges">' + bg + path + "</svg>\n";
        if (typeof cb === "function") {
          cb(null, svgTag);
        }
        return svgTag;
      };
    }
  });

  // node_modules/qrcode/lib/browser.js
  var require_browser = __commonJS({
    "node_modules/qrcode/lib/browser.js"(exports) {
      var canPromise = require_can_promise();
      var QRCode2 = require_qrcode();
      var CanvasRenderer = require_canvas();
      var SvgRenderer = require_svg_tag();
      function renderCanvas(renderFunc, canvas, text2, opts, cb) {
        const args = [].slice.call(arguments, 1);
        const argsNum = args.length;
        const isLastArgCb = typeof args[argsNum - 1] === "function";
        if (!isLastArgCb && !canPromise()) {
          throw new Error("Callback required as last argument");
        }
        if (isLastArgCb) {
          if (argsNum < 2) {
            throw new Error("Too few arguments provided");
          }
          if (argsNum === 2) {
            cb = text2;
            text2 = canvas;
            canvas = opts = void 0;
          } else if (argsNum === 3) {
            if (canvas.getContext && typeof cb === "undefined") {
              cb = opts;
              opts = void 0;
            } else {
              cb = opts;
              opts = text2;
              text2 = canvas;
              canvas = void 0;
            }
          }
        } else {
          if (argsNum < 1) {
            throw new Error("Too few arguments provided");
          }
          if (argsNum === 1) {
            text2 = canvas;
            canvas = opts = void 0;
          } else if (argsNum === 2 && !canvas.getContext) {
            opts = text2;
            text2 = canvas;
            canvas = void 0;
          }
          return new Promise(function(resolve, reject) {
            try {
              const data2 = QRCode2.create(text2, opts);
              resolve(renderFunc(data2, canvas, opts));
            } catch (e) {
              reject(e);
            }
          });
        }
        try {
          const data2 = QRCode2.create(text2, opts);
          cb(null, renderFunc(data2, canvas, opts));
        } catch (e) {
          cb(e);
        }
      }
      exports.create = QRCode2.create;
      exports.toCanvas = renderCanvas.bind(null, CanvasRenderer.render);
      exports.toDataURL = renderCanvas.bind(null, CanvasRenderer.renderToDataURL);
      exports.toString = renderCanvas.bind(null, function(data2, _, opts) {
        return SvgRenderer.render(data2, opts);
      });
    }
  });

  // src/app_host.ts
  var app_host_exports = {};
  __export(app_host_exports, {
    hostPosition: () => hostPosition,
    init: () => init,
    isSameHostPosition: () => isSameHostPosition,
    retryHostMutation: () => retryHostMutation
  });

  // src/live/api.ts
  var LiveApiError = class extends Error {
    constructor(message, code = "request_failed", status = 0, data2 = null) {
      super(message);
      __publicField(this, "code");
      __publicField(this, "data");
      __publicField(this, "status");
      this.name = "LiveApiError";
      this.code = code;
      this.status = status;
      this.data = data2;
    }
  };
  var LiveApi = class {
    constructor(config) {
      this.config = config;
    }
    async post(action, payload = {}, signal) {
      let response;
      try {
        response = await fetch(this.config.ajaxUrl, {
          method: "POST",
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            action,
            cmid: this.config.cmid,
            sesskey: this.config.sesskey,
            ...payload
          }),
          signal
        });
      } catch (error) {
        if (error instanceof DOMException && error.name === "AbortError") {
          throw error;
        }
        throw new LiveApiError(
          this.config.strings["live:connection:offline"] || "Die Verbindung ist gerade unterbrochen.",
          "network_error"
        );
      }
      let envelope;
      try {
        envelope = await response.json();
      } catch (_error) {
        throw new LiveApiError(
          this.config.strings["live:error:request"] || "Die Live-Session konnte die Anfrage nicht verarbeiten.",
          "invalid_response",
          response.status
        );
      }
      if (!response.ok || !envelope.ok || envelope.data === void 0) {
        const message = typeof envelope.message === "string" && envelope.message !== "" ? envelope.message : this.config.strings["live:error:request"] || "Die Live-Session konnte die Anfrage nicht verarbeiten.";
        const data2 = envelope.data && typeof envelope.data === "object" ? envelope.data : null;
        throw new LiveApiError(
          message,
          envelope.error || "request_failed",
          response.status,
          data2
        );
      }
      return envelope.data;
    }
  };

  // src/live/avatar.ts
  var SVG_NS = "http://www.w3.org/2000/svg";
  var AVATAR_KEYS = [
    "kiesel",
    "zweig",
    "federchen",
    "klecks",
    "kubus",
    "wirbel",
    "stern",
    "mondchen"
  ];
  var BODY_COLORS = {
    federchen: "var(--mq-color-honig-500)",
    kiesel: "var(--mq-color-tiefsee-500)",
    klecks: "var(--mq-color-beere-500)",
    kubus: "var(--mq-color-funke-500)",
    mondchen: "var(--mq-color-mitternacht-600)",
    stern: "var(--mq-color-honig-500)",
    wirbel: "var(--mq-color-tiefsee-400)",
    zweig: "var(--mq-color-trieb-600)"
  };
  var COLOR_TOKENS = {
    beere: "var(--mq-color-beere-500)",
    funke: "var(--mq-color-funke-500)",
    honig: "var(--mq-color-honig-500)",
    ink: "var(--mq-color-ink-300)",
    mitternacht: "var(--mq-color-mitternacht-600)",
    tiefsee: "var(--mq-color-tiefsee-500)",
    trieb: "var(--mq-color-trieb-600)"
  };
  function svgElement(name, attributes) {
    const element = document.createElementNS(SVG_NS, name);
    Object.entries(attributes).forEach(([attribute, value2]) => {
      element.setAttribute(attribute, value2);
    });
    return element;
  }
  function body(key, requestedColor) {
    const fill = requestedColor && COLOR_TOKENS[requestedColor] ? COLOR_TOKENS[requestedColor] : BODY_COLORS[key];
    switch (key) {
      case "zweig": {
        const group = svgElement("g", {});
        group.append(
          svgElement("ellipse", { cx: "60", cy: "66", fill, rx: "31", ry: "42" }),
          svgElement("polygon", {
            fill: "var(--mq-color-trieb-700)",
            points: "60,8 70,29 50,29"
          })
        );
        return group;
      }
      case "federchen":
        return svgElement("path", {
          d: "M60 15C91 39 97 78 60 106C23 78 29 39 60 15Z",
          fill,
          transform: "rotate(8 60 60)"
        });
      case "klecks":
        return svgElement("path", {
          d: "M60 14c22-4 46 10 44 34s6 46-18 54-52 2-50-26 2-58 24-62z",
          fill
        });
      case "kubus":
        return svgElement("path", {
          d: "M60 12c34 0 46 12 46 46s-12 46-46 46-46-12-46-46 12-46 46-46z",
          fill
        });
      case "wirbel":
        return svgElement("path", {
          d: "M60 14a46 46 0 1 1-32 78 34 34 0 1 0 22-56 18 18 0 1 1 10 34c-10 0-17-7-17-16 0-8 6-14 14-14 5 0 9 2 12 6",
          fill: "none",
          stroke: fill,
          "stroke-linecap": "round",
          "stroke-width": "22"
        });
      case "stern":
        return svgElement("polygon", {
          fill,
          points: "60,8 72,42 108,43 79,65 89,101 60,80 31,101 41,65 12,43 48,42",
          stroke: fill,
          "stroke-linejoin": "round",
          "stroke-width": "7"
        });
      case "mondchen":
        return svgElement("path", {
          d: "M79 15a46 46 0 1 0 26 72A39 39 0 0 1 79 15Z",
          fill
        });
      default:
        return svgElement("circle", { cx: "60", cy: "62", fill, r: "46" });
    }
  }
  function accessory(keyValue) {
    const key = typeof keyValue === "string" ? keyValue : "";
    if (key === "partyhut" || key === "party-hat") {
      const group = svgElement("g", { "data-avatar-accessory": key });
      group.append(
        svgElement("polygon", {
          fill: "var(--mq-color-honig-300)",
          points: "60,0 75,27 45,27"
        }),
        svgElement("circle", {
          cx: "60",
          cy: "2",
          fill: "var(--mq-color-beere-600)",
          r: "5"
        })
      );
      return group;
    }
    if (key === "brille" || key === "round-glasses") {
      const group = svgElement("g", {
        "data-avatar-accessory": key,
        fill: "none",
        stroke: "var(--mq-color-ink-900)",
        "stroke-width": "3"
      });
      group.append(
        svgElement("circle", { cx: "44", cy: "51", r: "11" }),
        svgElement("circle", { cx: "76", cy: "51", r: "11" }),
        svgElement("line", { x1: "55", x2: "65", y1: "51", y2: "51" })
      );
      return group;
    }
    if (key === "krone" || key === "crown") {
      return svgElement("polygon", {
        "data-avatar-accessory": key,
        fill: "var(--mq-color-honig-300)",
        points: "39,24 43,5 59,18 74,4 81,25",
        stroke: "var(--mq-color-honig-700)",
        "stroke-linejoin": "round",
        "stroke-width": "2"
      });
    }
    if (key === "medaille" || key === "medal") {
      const group = svgElement("g", { "data-avatar-accessory": key });
      group.append(
        svgElement("path", {
          d: "M82 82L90 105L98 82",
          fill: "var(--mq-color-beere-500)"
        }),
        svgElement("circle", {
          cx: "90",
          cy: "86",
          fill: "var(--mq-color-honig-300)",
          r: "11",
          stroke: "var(--mq-color-honig-700)",
          "stroke-width": "2"
        })
      );
      return group;
    }
    if (key === "funkenbadge") {
      const group = svgElement("g", { "data-avatar-accessory": key });
      group.append(
        svgElement("circle", {
          cx: "91",
          cy: "84",
          fill: "var(--mq-color-honig-100)",
          r: "12",
          stroke: "var(--mq-color-honig-700)",
          "stroke-width": "2"
        }),
        svgElement("path", {
          d: "M91 75l3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1z",
          fill: "var(--mq-color-funke-500)"
        })
      );
      return group;
    }
    if (key === "flamme") {
      return svgElement("path", {
        "data-avatar-accessory": key,
        d: "M94 91c-9-7-6-17 1-25 0 7 6 8 6 15 4-3 5-6 5-10 7 8 8 20-1 25-5 3-9 1-11-5z",
        fill: "var(--mq-color-funke-500)",
        stroke: "var(--mq-color-honig-700)",
        "stroke-width": "2"
      });
    }
    return null;
  }
  function appearance(value2) {
    if (value2 && typeof value2 === "object") {
      const parsed = appearance(value2.avatarKey || null);
      return {
        accessoryKey: value2.accessoryKey || parsed.accessoryKey,
        avatarKey: parsed.avatarKey,
        colorKey: value2.colorKey || parsed.colorKey
      };
    }
    const parts = typeof value2 === "string" ? value2.split(":") : [];
    const compactAccessory = parts.length === 2 && ["flamme", "funkenbadge", "partyhut"].includes(parts[1]) ? parts[1] : null;
    return {
      accessoryKey: parts[2] || compactAccessory,
      avatarKey: parts[0] || null,
      colorKey: compactAccessory ? null : parts[1] || null
    };
  }
  function createAvatar(appearanceValue) {
    const selected = appearance(appearanceValue);
    const key = AVATAR_KEYS.includes(selected.avatarKey) ? selected.avatarKey : "kiesel";
    const svg = svgElement("svg", {
      "aria-hidden": "true",
      class: `quizgeist-avatar quizgeist-avatar--${key}`,
      focusable: "false",
      viewBox: "0 0 120 120"
    });
    svg.dataset.avatarKey = key;
    if (selected.colorKey) {
      svg.dataset.avatarColor = selected.colorKey;
    }
    if (selected.accessoryKey) {
      svg.dataset.avatarAccessory = selected.accessoryKey;
    }
    const faceColor = "var(--mq-color-ink-900)";
    svg.append(
      body(key, selected.colorKey),
      svgElement("circle", {
        cx: "44",
        cy: "59",
        fill: "var(--mq-color-honig-200)",
        opacity: ".45",
        r: "8"
      }),
      svgElement("circle", {
        cx: "76",
        cy: "59",
        fill: "var(--mq-color-honig-200)",
        opacity: ".45",
        r: "8"
      }),
      svgElement("circle", { cx: "44", cy: "52", fill: faceColor, r: "5" }),
      svgElement("circle", { cx: "76", cy: "52", fill: faceColor, r: "5" }),
      svgElement("path", {
        d: "M47 69Q60 79 73 69",
        fill: "none",
        stroke: faceColor,
        "stroke-linecap": "round",
        "stroke-width": "4"
      })
    );
    const accessoryLayer = accessory(selected.accessoryKey);
    if (accessoryLayer) {
      svg.append(accessoryLayer);
    }
    return svg;
  }

  // src/live/dom.ts
  function appendChildren(parent, ...children) {
    for (const child of children) {
      if (child === null || child === void 0 || child === false) {
        continue;
      }
      parent.append(
        child instanceof Node ? child : document.createTextNode(String(child))
      );
    }
  }
  function liveElement(tagName, className = "", attributes = {}) {
    const node = document.createElement(tagName);
    if (className !== "") {
      node.className = className;
    }
    for (const [name, value2] of Object.entries(attributes)) {
      if (value2 === void 0 || value2 === null || value2 === false) {
        continue;
      }
      if (name === "text") {
        node.textContent = String(value2);
      } else if (name === "checked" && node instanceof HTMLInputElement) {
        node.checked = Boolean(value2);
      } else if (name === "disabled" && "disabled" in node) {
        node.disabled = Boolean(value2);
      } else if (name === "selected" && node instanceof HTMLOptionElement) {
        node.selected = Boolean(value2);
      } else if (name === "value" && "value" in node) {
        node.value = String(value2);
      } else if (value2 === true) {
        node.setAttribute(name, "");
      } else {
        node.setAttribute(name, String(value2));
      }
    }
    return node;
  }
  function liveButton(label, className = "", attributes = {}) {
    return liveElement("button", className, {
      type: "button",
      text: label,
      ...attributes
    });
  }
  function liveVisuallyHidden(text2) {
    return liveElement("span", "quizgeist-live-visually-hidden", { text: text2 });
  }
  function formatJoinCode(joinCode) {
    const cleaned = joinCode.replace(/\s+/g, "");
    return cleaned.length === 6 ? `${cleaned.slice(0, 3)} ${cleaned.slice(3)}` : cleaned;
  }
  function setNodeText(root, selector, value2) {
    const node = root.querySelector(selector);
    if (node && node.textContent !== String(value2)) {
      node.textContent = String(value2);
    }
  }
  function isAbortError(error) {
    return error instanceof DOMException && error.name === "AbortError";
  }

  // src/live/poller.ts
  var MIN_POLL_DELAY_MS = 1100;
  var MAX_ACTIVE_POLL_DELAY_MS = 4e3;
  var MAX_ERROR_DELAY_MS = 8e3;
  var AdaptivePoller = class {
    constructor(request, onError) {
      this.request = request;
      this.onError = onError;
      __publicField(this, "controller", null);
      __publicField(this, "consecutiveFailures", 0);
      __publicField(this, "inFlight", false);
      __publicField(this, "kickPending", false);
      __publicField(this, "running", false);
      __publicField(this, "timeoutId", null);
    }
    start(immediate = true) {
      if (this.running) {
        return;
      }
      this.running = true;
      this.consecutiveFailures = 0;
      this.schedule(immediate ? 0 : MIN_POLL_DELAY_MS);
    }
    stop() {
      var _a;
      this.running = false;
      this.kickPending = false;
      if (this.timeoutId !== null) {
        window.clearTimeout(this.timeoutId);
        this.timeoutId = null;
      }
      (_a = this.controller) == null ? void 0 : _a.abort();
      this.controller = null;
    }
    kick() {
      if (!this.running) {
        this.start(true);
        return;
      }
      if (this.inFlight) {
        this.kickPending = true;
        return;
      }
      if (this.timeoutId !== null) {
        window.clearTimeout(this.timeoutId);
        this.timeoutId = null;
      }
      this.schedule(0);
    }
    isRunning() {
      return this.running;
    }
    schedule(delayMs) {
      if (!this.running) {
        return;
      }
      this.timeoutId = window.setTimeout(() => {
        this.timeoutId = null;
        void this.tick();
      }, Math.max(0, delayMs));
    }
    async tick() {
      var _a;
      if (!this.running || this.inFlight) {
        return;
      }
      this.inFlight = true;
      this.controller = new AbortController();
      let nextDelay = MIN_POLL_DELAY_MS;
      try {
        const result = await this.request(this.controller.signal);
        this.consecutiveFailures = 0;
        nextDelay = this.successDelay(result == null ? void 0 : result.pollAfterMs);
      } catch (error) {
        if (error instanceof DOMException && error.name === "AbortError") {
          return;
        }
        this.consecutiveFailures += 1;
        const decision = this.onError(error, this.consecutiveFailures) || {};
        if (decision.stop) {
          this.running = false;
          return;
        }
        nextDelay = (_a = decision.delayMs) != null ? _a : Math.min(
          MAX_ERROR_DELAY_MS,
          MIN_POLL_DELAY_MS * 2 ** Math.min(3, this.consecutiveFailures - 1)
        );
      } finally {
        this.inFlight = false;
        this.controller = null;
      }
      if (!this.running) {
        return;
      }
      if (this.kickPending) {
        this.kickPending = false;
        this.schedule(0);
        return;
      }
      this.schedule(nextDelay);
    }
    successDelay(requestedDelay) {
      if (document.visibilityState === "hidden") {
        return MAX_ACTIVE_POLL_DELAY_MS;
      }
      const numericDelay = Number.isFinite(requestedDelay) ? Number(requestedDelay) : MIN_POLL_DELAY_MS;
      return Math.max(
        MIN_POLL_DELAY_MS,
        Math.min(MAX_ACTIVE_POLL_DELAY_MS, numericDelay)
      );
    }
  };

  // src/live/media.ts
  var IMAGE_MIME_TYPES = /* @__PURE__ */ new Set([
    "image/png",
    "image/jpeg",
    "image/gif",
    "image/webp",
    "image/svg+xml"
  ]);
  var VIDEO_MIME_TYPES = /* @__PURE__ */ new Set([
    "video/mp4",
    "video/webm",
    "video/ogg"
  ]);
  var AUDIO_MIME_TYPES = /* @__PURE__ */ new Set([
    "audio/mp4",
    "audio/webm",
    "audio/mp3",
    "audio/mpeg",
    "audio/ogg",
    "audio/wav",
    "audio/x-wav",
    "application/ogg"
  ]);
  function mediaKind(mimetype) {
    if (IMAGE_MIME_TYPES.has(mimetype)) {
      return "image";
    }
    if (VIDEO_MIME_TYPES.has(mimetype)) {
      return "video";
    }
    if (AUDIO_MIME_TYPES.has(mimetype)) {
      return "audio";
    }
    return null;
  }
  function sameOriginUrl(raw) {
    try {
      const url = new URL(raw, document.baseURI);
      if (url.protocol !== "http:" && url.protocol !== "https:" || url.origin !== window.location.origin) {
        return null;
      }
      return url.href;
    } catch (_error) {
      return null;
    }
  }
  function createLiveMedia(source, options) {
    const rawUrl = typeof source.mediaUrl === "string" ? source.mediaUrl : "";
    const mimetype = source.mediaMimeType;
    if (rawUrl === "" || !mimetype) {
      return null;
    }
    const kind = mediaKind(mimetype);
    const url = sameOriginUrl(rawUrl);
    if (!kind || !url || kind !== "image" && options.allowPlayback === false) {
      return null;
    }
    if (kind === "image") {
      const image = document.createElement("img");
      image.className = options.className;
      image.src = url;
      image.alt = options.label;
      image.dataset.mediaMimeType = mimetype;
      return image;
    }
    const media = document.createElement(kind);
    media.className = options.className;
    media.controls = true;
    media.preload = "metadata";
    media.setAttribute("aria-label", options.label);
    media.dataset.mediaMimeType = mimetype;
    if (media instanceof HTMLVideoElement) {
      media.playsInline = true;
    }
    const mediaSource = document.createElement("source");
    mediaSource.src = url;
    mediaSource.type = mimetype;
    media.append(mediaSource);
    return media;
  }

  // src/live/qr.ts
  var QRCode = __toESM(require_browser());
  async function renderLocalQrCode(container, value2, accessibleLabel) {
    const canvas = document.createElement("canvas");
    canvas.className = "quizgeist-host-qr__canvas";
    canvas.setAttribute("role", "img");
    canvas.setAttribute("aria-label", accessibleLabel);
    canvas.textContent = accessibleLabel;
    await QRCode.toCanvas(canvas, value2, {
      color: {
        dark: "#1B1B1FFF",
        light: "#FFFFFFFF"
      },
      errorCorrectionLevel: "M",
      margin: 4,
      width: 188
    });
    container.replaceChildren(canvas);
  }

  // src/live/choices.ts
  var LIVE_CHOICE_SLOTS = ["a", "b", "c", "d", "e", "f"];
  function choiceSlot(qtype, index) {
    if (qtype === "truefalse" && index === 1) {
      return "d";
    }
    return LIVE_CHOICE_SLOTS[Math.max(0, Math.min(LIVE_CHOICE_SLOTS.length - 1, index))];
  }
  function choiceShape(slot) {
    return liveElement(
      "span",
      `quizgeist-live-choice-shape quizgeist-live-choice-shape--${slot}`,
      {
        "aria-hidden": "true",
        "data-choice-slot": slot
      }
    );
  }
  function choiceShapeStringKey(slot) {
    return `live:shape:${slot}`;
  }

  // src/live/qtype/registry.ts
  function data(question) {
    return question.typeData && typeof question.typeData === "object" ? question.typeData : {};
  }
  function value(question, key, fallback) {
    var _a;
    const source = data(question);
    const direct = question[key];
    const candidate = (_a = source[key]) != null ? _a : direct;
    return candidate === void 0 || candidate === null ? fallback : candidate;
  }
  function responseType(question) {
    if (typeof question.responseType === "string" && question.responseType !== "") {
      return question.responseType;
    }
    return {
      quiz: "choices",
      truefalse: "choices",
      poll: "choices",
      shortanswer: "text",
      puzzle: "order",
      wordcloud: "text",
      scale: "scale",
      slider: "slider",
      pin: "pin",
      reveal: "reveal",
      brainstorm: "brainstorm",
      open: "text",
      slide: "slide"
    }[question.qtype] || "text";
  }
  function responseRoot(question, kind, context) {
    return liveElement(
      "section",
      `quizgeist-live-response quizgeist-live-response--${kind} quizgeist-live-response--${context.audience}`,
      {
        "data-live-answer-kind": kind,
        "data-live-qtype": question.qtype
      }
    );
  }
  function submitButton(context, answer) {
    const submit = liveButton(
      context.text("play:answer:send", "Antwort senden"),
      context.audience === "player" ? "quizgeist-player-primary quizgeist-answer-send" : "quizgeist-host-button quizgeist-host-button--primary",
      {
        "data-action": "answer",
        "data-live-submit": true,
        disabled: context.disabled || answer() === null
      }
    );
    submit.addEventListener("click", () => {
      var _a, _b;
      const current = answer();
      if (current) {
        (_a = context.onTap) == null ? void 0 : _a.call(context);
        (_b = context.onSubmit) == null ? void 0 : _b.call(context, current);
      }
    });
    return submit;
  }
  function currentChoiceIds(answer) {
    return answer && "choiceIds" in answer && Array.isArray(answer.choiceIds) ? answer.choiceIds : [];
  }
  var renderChoices = (question, context) => {
    const root = responseRoot(question, "choices", context);
    const choices = Array.isArray(question.choices) ? question.choices : [];
    const multiple = Boolean(question.multiple);
    let selected = new Set(currentChoiceIds(context.answer));
    const grid = liveElement(
      "div",
      context.audience === "host" ? `quizgeist-host-answer-grid quizgeist-host-answer-grid--${choices.length <= 2 ? "two" : choices.length <= 4 ? "four" : "six"}` : "quizgeist-player-choices",
      {
        role: context.interactive ? multiple ? "group" : "radiogroup" : "list",
        "data-live-choice-grid": true
      }
    );
    const patch = () => {
      var _a;
      grid.querySelectorAll("[data-choice-id]").forEach((node) => {
        const checked = selected.has(node.dataset.choiceId || "");
        node.classList.toggle("is-selected", checked);
        if (multiple) {
          node.setAttribute("aria-pressed", checked ? "true" : "false");
        } else if (context.interactive) {
          node.setAttribute("aria-checked", checked ? "true" : "false");
          node.tabIndex = checked || selected.size === 0 && node === grid.querySelector("[data-choice-id]") ? 0 : -1;
        }
      });
      const answer = selected.size > 0 ? { kind: "choices", choiceIds: Array.from(selected) } : null;
      (_a = context.onChange) == null ? void 0 : _a.call(context, answer);
      const send = root.querySelector("[data-live-submit]");
      if (send) {
        send.disabled = Boolean(context.disabled || !answer);
      }
    };
    choices.forEach((choice, index) => {
      const slot = choiceSlot(question.qtype, index);
      const className = context.audience === "host" ? `quizgeist-host-answer quizgeist-host-answer--${slot}` : `quizgeist-choice quizgeist-choice--${slot}`;
      const tile = context.interactive ? liveButton(choice.text, className, {
        "data-action": "select-answer",
        "data-choice-id": choice.id,
        "data-choice-slot": slot,
        "data-live-choice-id": choice.id,
        disabled: context.disabled
      }) : liveElement("div", className, {
        "data-choice-id": choice.id,
        "data-choice-slot": slot,
        "data-live-choice-id": choice.id,
        role: "listitem"
      });
      tile.setAttribute(
        "aria-label",
        `${context.text(choiceShapeStringKey(slot), slot.toUpperCase())}: ${choice.text || context.text("editor:field:answerimage", "Antwortmedium")}`
      );
      if (context.interactive) {
        if (multiple) {
          tile.setAttribute("aria-pressed", selected.has(choice.id) ? "true" : "false");
        } else {
          tile.setAttribute("role", "radio");
          tile.setAttribute("aria-checked", selected.has(choice.id) ? "true" : "false");
        }
        tile.addEventListener("click", () => {
          var _a;
          (_a = context.onTap) == null ? void 0 : _a.call(context);
          if (multiple) {
            if (selected.has(choice.id)) {
              selected.delete(choice.id);
            } else {
              selected.add(choice.id);
            }
          } else {
            selected = /* @__PURE__ */ new Set([choice.id]);
          }
          patch();
        });
      }
      tile.replaceChildren(choiceShape(slot));
      const media = createLiveMedia(choice, {
        allowPlayback: false,
        className: context.audience === "host" ? "quizgeist-host-answer__media" : "quizgeist-choice__media",
        label: ""
      });
      if (media) {
        tile.append(media);
      }
      tile.append(liveElement("span", context.audience === "host" ? "quizgeist-host-answer__text" : "quizgeist-choice__text", { text: choice.text }));
      grid.append(tile);
    });
    if (context.interactive) {
      grid.addEventListener("keydown", (event) => {
        if (/^[1-6]$/.test(event.key)) {
          const choice = choices[Number(event.key) - 1];
          if (choice) {
            event.preventDefault();
            const control = grid.querySelector(
              `[data-choice-id="${CSS.escape(choice.id)}"]`
            );
            control == null ? void 0 : control.click();
            control == null ? void 0 : control.focus();
          }
        }
      });
    }
    root.append(grid);
    if (context.interactive) {
      root.append(submitButton(context, () => selected.size > 0 ? { kind: "choices", choiceIds: Array.from(selected) } : null));
    }
    patch();
    return root;
  };
  function textAnswer(answer) {
    return (answer == null ? void 0 : answer.kind) === "text" || (answer == null ? void 0 : answer.kind) === "brainstormIdea" ? answer.text : "";
  }
  function renderText(question, context, kind = "text") {
    const root = responseRoot(question, kind, context);
    const maxChars = Math.max(1, Number(value(question, "maxChars", question.qtype === "open" ? 2e3 : 160)));
    const multiline = question.qtype === "open" || question.qtype === "wordcloud" || question.qtype === "brainstorm";
    if (!context.interactive) {
      root.append(liveElement("p", "quizgeist-live-response__prompt", {
        text: question.qtype === "open" ? context.text("live:response:open", "Freie Antwort auf dem eigenen Ger\xE4t") : context.text("live:response:text", "Antwort auf dem eigenen Ger\xE4t eingeben")
      }));
      return root;
    }
    let current = textAnswer(context.answer);
    const label = liveElement("label", "quizgeist-live-response__label", {
      text: context.text("play:answer:text", "Deine Antwort")
    });
    const input = multiline ? liveElement("textarea", "quizgeist-live-text-answer", {
      "data-live-text-answer": true,
      maxlength: maxChars,
      rows: question.qtype === "open" ? 5 : 3,
      value: current
    }) : liveElement("input", "quizgeist-live-text-answer", {
      "data-live-text-answer": true,
      maxlength: maxChars,
      type: "text",
      value: current
    });
    const counter = liveElement("span", "quizgeist-live-character-count", {
      "aria-live": "polite",
      "data-live-character-count": true,
      text: `${current.length} / ${maxChars}`
    });
    label.append(input);
    const currentAnswer = () => current.trim() === "" ? null : { kind: "text", text: current.trim() };
    const submit = submitButton(context, currentAnswer);
    input.addEventListener("input", () => {
      var _a;
      current = input.value.slice(0, maxChars);
      counter.textContent = `${current.length} / ${maxChars}`;
      const answer = currentAnswer();
      (_a = context.onChange) == null ? void 0 : _a.call(context, answer);
      submit.disabled = Boolean(context.disabled || !answer);
    });
    root.append(label, counter, submit);
    return root;
  }
  var renderPuzzle = (question, context) => {
    var _a, _b;
    const root = responseRoot(question, "order", context);
    const items = value(question, "items", []);
    let order = ((_a = context.answer) == null ? void 0 : _a.kind) === "order" ? context.answer.orderIds.filter((id) => items.some((item) => item.id === id)) : items.map((item) => item.id);
    items.forEach((item) => {
      if (!order.includes(item.id)) {
        order.push(item.id);
      }
    });
    const list = liveElement("ol", "quizgeist-live-puzzle", {
      "data-live-puzzle": true
    });
    let draggedId = "";
    const answer = () => ({ kind: "order", orderIds: [...order] });
    const render = () => {
      list.replaceChildren();
      order.forEach((id, index) => {
        const item = items.find((candidate) => candidate.id === id);
        if (!item) {
          return;
        }
        const row = liveElement("li", "quizgeist-live-puzzle__item", {
          "data-live-puzzle-item": item.id,
          draggable: context.interactive && !context.disabled
        });
        const content = liveElement("span", "quizgeist-live-puzzle__text", {
          text: item.text
        });
        row.append(
          liveElement("span", "quizgeist-live-puzzle__handle", {
            "aria-hidden": "true",
            text: "\u283F"
          }),
          liveElement("span", "quizgeist-live-puzzle__index", { text: String(index + 1) }),
          content
        );
        const media = createLiveMedia(item, {
          allowPlayback: false,
          className: "quizgeist-live-puzzle__media",
          label: ""
        });
        if (media) {
          content.append(media);
        }
        if (context.interactive) {
          const up = liveButton("\u2191", "quizgeist-live-order-button", {
            "aria-label": context.text("live:puzzle:up", "Nach oben"),
            "data-live-order-up": item.id,
            disabled: context.disabled || index === 0
          });
          const down = liveButton("\u2193", "quizgeist-live-order-button", {
            "aria-label": context.text("live:puzzle:down", "Nach unten"),
            "data-live-order-down": item.id,
            disabled: context.disabled || index >= order.length - 1
          });
          const move = (offset) => {
            var _a2, _b2;
            const next = index + offset;
            if (next < 0 || next >= order.length) {
              return;
            }
            [order[index], order[next]] = [order[next], order[index]];
            (_a2 = context.onTap) == null ? void 0 : _a2.call(context);
            (_b2 = context.onChange) == null ? void 0 : _b2.call(context, answer());
            render();
          };
          up.addEventListener("click", () => move(-1));
          down.addEventListener("click", () => move(1));
          row.addEventListener("dragstart", () => {
            draggedId = item.id;
          });
          row.addEventListener("dragover", (event) => event.preventDefault());
          row.addEventListener("drop", (event) => {
            var _a2;
            event.preventDefault();
            const from = order.indexOf(draggedId);
            const to = order.indexOf(item.id);
            if (from >= 0 && to >= 0 && from !== to) {
              order.splice(to, 0, order.splice(from, 1)[0]);
              (_a2 = context.onChange) == null ? void 0 : _a2.call(context, answer());
              render();
            }
          });
          const controls = liveElement("span", "quizgeist-live-puzzle__controls");
          controls.append(up, down);
          row.append(controls);
        }
        list.append(row);
      });
    };
    render();
    root.append(list);
    if (context.interactive) {
      (_b = context.onChange) == null ? void 0 : _b.call(context, answer());
      root.append(submitButton(context, answer));
    }
    return root;
  };
  var renderScale = (question, context) => {
    var _a;
    const root = responseRoot(question, "number", context);
    const steps = Math.max(3, Math.min(10, Number(value(question, "steps", 5))));
    const minLabel = String(value(question, "minLabel", "1"));
    const maxLabel = String(value(question, "maxLabel", String(steps)));
    let selected = ((_a = context.answer) == null ? void 0 : _a.kind) === "number" ? context.answer.value : 0;
    const labels = liveElement("div", "quizgeist-live-scale__labels", {
      "aria-hidden": "true",
      "data-live-scale-labels": true
    });
    labels.style.display = "flex";
    labels.style.gap = "var(--mq-space-3)";
    labels.style.justifyContent = "space-between";
    labels.style.width = "100%";
    labels.append(
      liveElement("span", "", { text: minLabel }),
      liveElement("span", "", { text: maxLabel })
    );
    const controls = liveElement("div", "quizgeist-live-scale", {
      role: context.interactive ? "radiogroup" : "list"
    });
    const patch = () => {
      var _a2;
      controls.querySelectorAll("[data-live-scale-value]").forEach((button) => {
        const checked = Number(button.dataset.liveScaleValue) === selected;
        button.classList.toggle("is-selected", checked);
        button.setAttribute("aria-checked", checked ? "true" : "false");
        button.tabIndex = checked || selected === 0 && button.dataset.liveScaleValue === "1" ? 0 : -1;
      });
      (_a2 = context.onChange) == null ? void 0 : _a2.call(context, selected > 0 ? { kind: "number", value: selected } : null);
      const submit = root.querySelector("[data-live-submit]");
      if (submit) {
        submit.disabled = Boolean(context.disabled || selected <= 0);
      }
    };
    for (let number = 1; number <= steps; number += 1) {
      const chip = context.interactive ? liveButton(String(number), "quizgeist-live-scale__value", {
        "aria-label": number === 1 ? `${number}: ${minLabel}` : number === steps ? `${number}: ${maxLabel}` : String(number),
        "data-live-scale-value": number,
        disabled: context.disabled,
        role: "radio"
      }) : liveElement("span", "quizgeist-live-scale__value", {
        "data-live-scale-value": number,
        role: "listitem",
        text: String(number)
      });
      if (context.interactive) {
        chip.addEventListener("click", () => {
          var _a2;
          selected = number;
          (_a2 = context.onTap) == null ? void 0 : _a2.call(context);
          patch();
        });
      }
      controls.append(chip);
    }
    if (context.interactive) {
      controls.addEventListener("keydown", (event) => {
        var _a2, _b;
        if (!["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown", "Home", "End"].includes(event.key)) {
          return;
        }
        const focused = event.target instanceof HTMLElement ? Number(event.target.dataset.liveScaleValue || 0) : 0;
        const origin = selected > 0 ? selected : Math.max(1, focused);
        let next = origin;
        if (event.key === "Home") {
          next = 1;
        } else if (event.key === "End") {
          next = steps;
        } else if (event.key === "ArrowLeft" || event.key === "ArrowDown") {
          next = Math.max(1, origin - 1);
        } else {
          next = Math.min(steps, origin + 1);
        }
        event.preventDefault();
        selected = next;
        (_a2 = context.onTap) == null ? void 0 : _a2.call(context);
        patch();
        (_b = controls.querySelector(
          `[data-live-scale-value="${next}"]`
        )) == null ? void 0 : _b.focus();
      });
    }
    root.append(labels, controls);
    if (context.interactive) {
      root.append(submitButton(context, () => selected > 0 ? { kind: "number", value: selected } : null));
      patch();
    }
    return root;
  };
  var renderSlider = (question, context) => {
    var _a;
    const root = responseRoot(question, "number", context);
    const min = Number(value(question, "min", 0));
    const max = Number(value(question, "max", 100));
    const step = Math.max(1e-6, Number(value(question, "step", 1)));
    const maximumStep = Math.max(0, Math.floor((max - min) / step + 1e-7));
    const snap = (next) => {
      const stepIndex = Math.max(
        0,
        Math.min(maximumStep, Math.round((next - min) / step))
      );
      return Number((min + stepIndex * step).toFixed(6));
    };
    let current = ((_a = context.answer) == null ? void 0 : _a.kind) === "number" ? snap(context.answer.value) : snap((min + max) / 2);
    const output = liveElement("output", "quizgeist-live-slider__value", {
      "data-live-slider-value": true,
      text: String(current)
    });
    const slider = liveElement("input", "quizgeist-live-slider", {
      "data-live-slider": true,
      disabled: context.disabled || !context.interactive,
      max,
      min,
      step,
      type: "range",
      value: current
    });
    const set = (next) => {
      var _a2;
      current = snap(next);
      slider.value = String(current);
      output.value = String(current);
      output.textContent = String(current);
      (_a2 = context.onChange) == null ? void 0 : _a2.call(context, { kind: "number", value: current });
    };
    slider.addEventListener("input", () => set(Number(slider.value)));
    const minus = liveButton("\u2212", "quizgeist-live-slider__adjust", {
      "aria-label": context.text("live:slider:minus", "Wert verkleinern"),
      "data-live-number-minus": true,
      disabled: context.disabled || !context.interactive
    });
    const plus = liveButton("+", "quizgeist-live-slider__adjust", {
      "aria-label": context.text("live:slider:plus", "Wert vergr\xF6\xDFern"),
      "data-live-number-plus": true,
      disabled: context.disabled || !context.interactive
    });
    minus.addEventListener("click", () => set(current - step));
    plus.addEventListener("click", () => set(current + step));
    const row = liveElement("div", "quizgeist-live-slider__controls");
    row.append(minus, slider, plus);
    root.append(output, row);
    if (context.interactive) {
      set(current);
      root.append(submitButton(context, () => ({ kind: "number", value: current })));
    }
    return root;
  };
  function pinMedia(question) {
    const typeData = data(question);
    return {
      mediaMimeType: typeData.mediaMimeType || question.mediaMimeType,
      mediaUrl: typeData.mediaUrl || question.mediaUrl
    };
  }
  function pinImageGeometry(canvas, image) {
    const canvasBounds = canvas.getBoundingClientRect();
    if (canvasBounds.width <= 0 || canvasBounds.height <= 0) {
      return null;
    }
    const imageBounds = image == null ? void 0 : image.getBoundingClientRect();
    const box = imageBounds && imageBounds.width > 0 && imageBounds.height > 0 ? imageBounds : canvasBounds;
    let width = box.width;
    let height = box.height;
    let left = box.left - canvasBounds.left;
    let top = box.top - canvasBounds.top;
    if (image && image.naturalWidth > 0 && image.naturalHeight > 0) {
      const scale = Math.min(
        box.width / image.naturalWidth,
        box.height / image.naturalHeight
      );
      width = image.naturalWidth * scale;
      height = image.naturalHeight * scale;
      left += (box.width - width) / 2;
      top += (box.height - height) / 2;
    }
    return {
      canvasHeight: canvasBounds.height,
      canvasWidth: canvasBounds.width,
      height,
      left,
      top,
      width
    };
  }
  function positionPinNode(canvas, image, node, point) {
    const geometry = pinImageGeometry(canvas, image);
    if (!geometry) {
      return;
    }
    node.style.left = `${(geometry.left + geometry.width * point.x / 100) * 100 / geometry.canvasWidth}%`;
    node.style.top = `${(geometry.top + geometry.height * point.y / 100) * 100 / geometry.canvasHeight}%`;
  }
  var renderPin = (question, context) => {
    var _a;
    const root = responseRoot(question, "pin", context);
    let point = ((_a = context.answer) == null ? void 0 : _a.kind) === "pin" ? { x: context.answer.x, y: context.answer.y } : null;
    const canvas = liveElement("div", "quizgeist-live-pin", {
      "aria-label": context.text("live:pin:place", "Pin auf dem Bild platzieren"),
      "aria-disabled": context.interactive && context.disabled ? "true" : void 0,
      "data-live-pin-canvas": true,
      role: context.interactive ? "button" : "img",
      tabindex: context.interactive && !context.disabled ? 0 : void 0
    });
    const image = createLiveMedia(pinMedia(question), {
      allowPlayback: false,
      className: "quizgeist-live-pin__image",
      label: question.questionText
    });
    const pinImage = image instanceof HTMLImageElement ? image : null;
    if (image) {
      canvas.append(image);
    }
    const marker = liveElement("span", "quizgeist-live-pin__marker", {
      "aria-hidden": "true",
      "data-live-pin-marker": true,
      hidden: !point
    });
    canvas.append(marker);
    const patch = () => {
      var _a2;
      marker.hidden = !point;
      if (point) {
        positionPinNode(canvas, pinImage, marker, point);
        canvas.setAttribute(
          "aria-label",
          `${context.text("live:pin:place", "Pin auf dem Bild platzieren")}: ${Math.round(point.x)} %, ${Math.round(point.y)} %`
        );
        (_a2 = context.onChange) == null ? void 0 : _a2.call(context, { kind: "pin", x: point.x, y: point.y });
      }
      const submit = root.querySelector("[data-live-submit]");
      if (submit) {
        submit.disabled = Boolean(context.disabled || !point);
      }
    };
    if (context.interactive) {
      canvas.addEventListener("pointerdown", (event) => {
        var _a2;
        if (context.disabled) {
          return;
        }
        const bounds = canvas.getBoundingClientRect();
        const geometry = pinImageGeometry(canvas, pinImage);
        if (!geometry) {
          return;
        }
        const x = event.clientX - bounds.left - geometry.left;
        const y = event.clientY - bounds.top - geometry.top;
        if (x < 0 || x > geometry.width || y < 0 || y > geometry.height) {
          return;
        }
        point = {
          x: Math.max(0, Math.min(100, x * 100 / geometry.width)),
          y: Math.max(0, Math.min(100, y * 100 / geometry.height))
        };
        (_a2 = context.onTap) == null ? void 0 : _a2.call(context);
        patch();
      });
      canvas.addEventListener("keydown", (event) => {
        var _a2;
        if (context.disabled) {
          return;
        }
        const distance = event.shiftKey ? 5 : 1;
        const next = point ? { ...point } : { x: 50, y: 50 };
        if (event.key === "ArrowLeft") {
          next.x -= distance;
        } else if (event.key === "ArrowRight") {
          next.x += distance;
        } else if (event.key === "ArrowUp") {
          next.y -= distance;
        } else if (event.key === "ArrowDown") {
          next.y += distance;
        } else if ((event.key === " " || event.key === "Enter") && !point) {
        } else {
          return;
        }
        event.preventDefault();
        point = {
          x: Math.max(0, Math.min(100, next.x)),
          y: Math.max(0, Math.min(100, next.y))
        };
        (_a2 = context.onTap) == null ? void 0 : _a2.call(context);
        patch();
      });
    }
    pinImage == null ? void 0 : pinImage.addEventListener("load", patch, { once: true });
    patch();
    root.append(canvas);
    if (context.interactive) {
      root.append(submitButton(context, () => point ? { kind: "pin", x: point.x, y: point.y } : null));
    }
    return root;
  };
  function renderRevealImage(question, context) {
    const frame = liveElement("div", "quizgeist-live-image-reveal", {
      "data-live-reveal-grid": true
    });
    const media = createLiveMedia(pinMedia(question), {
      allowPlayback: false,
      className: "quizgeist-live-image-reveal__image",
      label: question.questionText
    });
    if (media) {
      frame.append(media);
    }
    const gridSize = Math.max(3, Math.min(6, Number(value(question, "grid", 3))));
    const count = gridSize * gridSize;
    const order = value(question, "tileOrder", Array.from({ length: count }, (_, i) => i));
    const seconds = Math.max(1, Number(value(question, "revealSeconds", 12)));
    const stepMs = Math.max(80, Number(value(question, "stepMs", seconds * 1e3 / count)));
    const phaseStartedAtMs = Number(context.phaseStartedAtMs);
    const elapsedMs = Number.isFinite(phaseStartedAtMs) && phaseStartedAtMs > 0 ? Math.max(0, context.nowMs - phaseStartedAtMs) : 0;
    const bonus = liveElement("span", "quizgeist-live-image-reveal__bonus", {
      "aria-live": "off",
      "data-live-reveal-bonus": true,
      "data-live-reveal-label": context.text("live:reveal:bonus", "Fr\xFChbonus"),
      "data-live-reveal-start-ms": phaseStartedAtMs,
      "data-live-reveal-step-ms": stepMs,
      "data-live-reveal-total": count
    });
    const overlay = liveElement("div", "quizgeist-live-image-reveal__tiles", {
      "aria-hidden": "true"
    });
    overlay.style.gridTemplateColumns = `repeat(${gridSize}, 1fr)`;
    for (let index = 0; index < count; index += 1) {
      const orderIndex = Math.max(0, order.indexOf(index));
      const tile = liveElement("span", "quizgeist-live-image-reveal__tile");
      tile.style.animationDelay = `${orderIndex * stepMs - elapsedMs}ms`;
      overlay.append(tile);
    }
    frame.append(overlay, bonus);
    patchLiveResponseClock(frame, context.nowMs);
    return frame;
  }
  function patchLiveResponseClock(root, nowMs) {
    root.querySelectorAll("[data-live-reveal-bonus]").forEach((node) => {
      const start = Number(node.dataset.liveRevealStartMs);
      const step = Number(node.dataset.liveRevealStepMs);
      const total = Math.max(1, Number(node.dataset.liveRevealTotal));
      if (!Number.isFinite(start) || !Number.isFinite(step) || step <= 0) {
        return;
      }
      const revealed = Math.max(0, Math.floor(Math.max(0, nowMs - start) / step));
      const remaining = Math.max(0, total - revealed);
      node.textContent = `${node.dataset.liveRevealLabel || "Fr\xFChbonus"}: ${remaining} / ${total}`;
    });
  }
  var renderImageReveal = (question, context) => {
    var _a;
    const root = responseRoot(question, "reveal", context);
    root.append(renderRevealImage(question, context));
    if (context.interactive) {
      const form = renderText(question, context, "text");
      (_a = form.querySelector("[data-live-qtype]")) == null ? void 0 : _a.removeAttribute("data-live-qtype");
      root.append(...Array.from(form.childNodes));
    }
    return root;
  };
  function brainstormStage(question) {
    return String(
      question.interactionStage || value(question, "interactionStage", value(question, "stage", "collect"))
    );
  }
  function brainstormGroups(question, aggregate) {
    const aggregateGroups = objectRows(aggregateRecord(aggregate).groups).map((group) => ({
      ideas: objectRows(group.ideas).map((idea) => ({
        groupKey: String(group.key || ""),
        id: String(idea.id || idea.key || ""),
        own: idea.own === true,
        text: String(idea.text || ""),
        votes: idea.votes === null || idea.votes === void 0 ? void 0 : Number(idea.votes)
      })),
      key: String(group.key || ""),
      label: String(group.label || ""),
      votes: group.votes === null || group.votes === void 0 ? void 0 : Number(group.votes)
    }));
    if (aggregateGroups.length > 0) {
      return aggregateGroups;
    }
    const groups = value(question, "groups", []);
    if (Array.isArray(groups) && groups.length > 0) {
      return groups;
    }
    const ideas = value(question, "ideas", []);
    return ideas.length > 0 ? [{
      key: "all",
      label: "",
      ideas,
      votes: ideas.reduce((sum, idea) => sum + Number(idea.votes || 0), 0)
    }] : [];
  }
  var renderBrainstorm = (question, context) => {
    var _a;
    const root = responseRoot(question, "brainstorm", context);
    const stage = brainstormStage(question);
    root.dataset.liveBrainstormStage = stage;
    root.append(liveElement("p", "quizgeist-live-brainstorm__stage", {
      "data-live-brainstorm-stage": stage,
      text: context.text(`live:brainstorm:${stage}`, {
        collect: "Ideen sammeln",
        group: "Ideen gruppieren",
        vote: "Ideen abstimmen",
        done: "Ergebnis"
      }[stage] || stage)
    }));
    if (stage === "collect" && context.interactive) {
      let current = "";
      const input = liveElement("textarea", "quizgeist-live-text-answer", {
        "data-live-brainstorm-idea": true,
        "data-live-text-answer": true,
        maxlength: Math.max(
          1,
          Number(value(question, "maxChars", value(question, "maxIdeaChars", 280)))
        ),
        rows: 3
      });
      const submit = liveButton(
        context.text("live:brainstorm:submit", "Idee einreichen"),
        "quizgeist-player-primary",
        {
          "data-live-submit": true,
          disabled: true
        }
      );
      input.addEventListener("input", () => {
        var _a2;
        current = input.value.trim();
        submit.disabled = Boolean(context.disabled || current === "");
        (_a2 = context.onChange) == null ? void 0 : _a2.call(context, current === "" ? null : { kind: "brainstormIdea", text: current });
      });
      submit.addEventListener("click", () => {
        var _a2;
        if (current !== "") {
          const answer = { kind: "brainstormIdea", text: current };
          (_a2 = context.onSubmit) == null ? void 0 : _a2.call(context, answer);
        }
      });
      root.append(input, submit);
      return root;
    }
    const groups = brainstormGroups(question, context.aggregate);
    const list = liveElement("div", "quizgeist-live-brainstorm__groups");
    let selectedGroup = ((_a = context.answer) == null ? void 0 : _a.kind) === "brainstormVote" ? context.answer.groupKey : String(value(question, "ownGroupKey", ""));
    groups.forEach((group) => {
      const section = stage === "vote" && context.interactive ? liveButton("", "quizgeist-live-brainstorm__group", {
        "aria-pressed": selectedGroup === group.key ? "true" : "false",
        "data-live-brainstorm-vote": group.key,
        disabled: context.disabled
      }) : liveElement("section", "quizgeist-live-brainstorm__group");
      section.dataset.liveBrainstormGroup = group.key;
      if (group.label) {
        section.append(liveElement("h3", "", { text: group.label }));
      }
      group.ideas.forEach((idea) => {
        const ideaNode = liveElement("article", "quizgeist-live-brainstorm__idea", {
          "data-live-brainstorm-idea": idea.id,
          text: idea.text
        });
        if (typeof idea.votes === "number") {
          ideaNode.append(liveElement("span", "quizgeist-live-brainstorm__votes", {
            text: `\u2665 ${idea.votes}`
          }));
        }
        section.append(ideaNode);
      });
      if (stage === "vote" && context.interactive) {
        section.addEventListener("click", () => {
          var _a2, _b;
          selectedGroup = group.key;
          (_a2 = context.onTap) == null ? void 0 : _a2.call(context);
          list.querySelectorAll("[data-live-brainstorm-vote]").forEach((node) => {
            const checked = node.dataset.liveBrainstormVote === selectedGroup;
            node.classList.toggle("is-selected", checked);
            node.setAttribute("aria-pressed", checked ? "true" : "false");
          });
          (_b = context.onChange) == null ? void 0 : _b.call(context, {
            groupKey: selectedGroup,
            kind: "brainstormVote"
          });
          const submit = root.querySelector("[data-live-submit]");
          if (submit) {
            submit.disabled = Boolean(context.disabled || selectedGroup === "");
          }
        });
      }
      list.append(section);
    });
    root.append(list);
    if (stage === "vote" && context.interactive) {
      root.append(submitButton(context, () => selectedGroup !== "" ? { groupKey: selectedGroup, kind: "brainstormVote" } : null));
    }
    return root;
  };
  var REACTION_LABEL_FALLBACKS = {
    clap: "Applaus",
    heart: "Herz",
    idea: "Idee",
    laugh: "Lachen",
    wow: "Wow"
  };
  function reactionOptions(question, context) {
    const source = value(question, "reactionOptions", value(question, "reactions", []));
    if (Array.isArray(source)) {
      return source.flatMap((entry, index) => {
        if (typeof entry === "string") {
          return [{
            id: entry,
            emoji: entry,
            label: context.text(
              `live:reaction:${entry}`,
              REACTION_LABEL_FALLBACKS[entry] || "Reaktion"
            )
          }];
        }
        if (entry && typeof entry === "object") {
          const record = entry;
          const id = String(record.id || record.reaction || index);
          return [{
            count: Number(record.count || 0),
            emoji: String(record.emoji || record.reaction || "\u2728"),
            id,
            label: String(
              record.label || context.text(
                `live:reaction:${id}`,
                REACTION_LABEL_FALLBACKS[id] || "Reaktion"
              )
            )
          }];
        }
        return [];
      });
    }
    return [
      { emoji: "\u2764\uFE0F", id: "heart", label: context.text("live:reaction:heart", "Herz") },
      { emoji: "\u{1F44F}", id: "clap", label: context.text("live:reaction:clap", "Applaus") },
      { emoji: "\u{1F4A1}", id: "idea", label: context.text("live:reaction:idea", "Idee") },
      { emoji: "\u{1F604}", id: "laugh", label: context.text("live:reaction:laugh", "Lachen") },
      { emoji: "\u{1F62E}", id: "wow", label: context.text("live:reaction:wow", "Wow") }
    ];
  }
  var renderSlide = (question, context) => {
    const root = responseRoot(question, "slide", context);
    const layout = String(value(question, "layout", "title"));
    const card = liveElement("article", `quizgeist-live-slide quizgeist-live-slide--${layout}`, {
      "data-live-slide": layout
    });
    const title = String(value(question, "title", question.questionText));
    const body2 = String(value(question, "body", ""));
    if (title) {
      card.append(liveElement("h2", "quizgeist-live-slide__title", { text: title }));
    }
    if (layout === "quote") {
      card.append(
        liveElement("blockquote", "quizgeist-live-slide__quote", {
          text: String(value(question, "quote", body2))
        }),
        liveElement("cite", "quizgeist-live-slide__attribution", {
          text: String(value(question, "attribution", ""))
        })
      );
    } else if (layout === "bullets") {
      const list = liveElement("ul", "quizgeist-live-slide__bullets");
      value(question, "bullets", []).forEach((bullet) => {
        list.append(liveElement("li", "", { text: bullet }));
      });
      card.append(list);
    } else if (body2) {
      card.append(liveElement("p", "quizgeist-live-slide__body", { text: body2 }));
    }
    const media = createLiveMedia(pinMedia(question), {
      className: "quizgeist-live-slide__media",
      label: title
    });
    if (media) {
      card.append(media);
    }
    root.append(card);
    if (value(question, "reactions", false) !== false) {
      const reactions = liveElement("div", "quizgeist-live-reactions", {
        "aria-label": context.text("live:reactions:title", "Live-Reaktionen"),
        "data-live-reactions": true,
        role: context.interactive ? "radiogroup" : "list"
      });
      const ownReaction = String(value(question, "ownReaction", ""));
      reactionOptions(question, context).forEach((reaction) => {
        const node = context.interactive ? liveButton(reaction.emoji, "quizgeist-live-reaction", {
          "aria-label": reaction.label,
          "aria-checked": ownReaction === reaction.id ? "true" : "false",
          "data-live-reaction": reaction.id,
          disabled: context.disabled,
          role: "radio"
        }) : liveElement("span", "quizgeist-live-reaction", {
          "data-live-reaction": reaction.id,
          role: "listitem",
          text: reaction.emoji
        });
        if (context.interactive) {
          node.addEventListener("click", () => {
            var _a, _b, _c;
            (_a = context.onTap) == null ? void 0 : _a.call(context);
            const answer = {
              kind: "reaction",
              reaction: reaction.id
            };
            (_b = context.onChange) == null ? void 0 : _b.call(context, answer);
            (_c = context.onSubmit) == null ? void 0 : _c.call(context, answer);
            reactions.querySelectorAll("[data-live-reaction]").forEach((candidate) => {
              candidate.setAttribute(
                "aria-checked",
                candidate === node ? "true" : "false"
              );
            });
          });
        }
        if (!context.interactive && typeof reaction.count === "number") {
          node.append(liveElement("strong", "", { text: String(reaction.count) }));
        }
        reactions.append(node);
      });
      root.append(reactions);
    }
    return root;
  };
  var RENDERERS = {
    brainstorm: renderBrainstorm,
    choices: renderChoices,
    choice: renderChoices,
    order: renderPuzzle,
    puzzle: renderPuzzle,
    reaction: renderSlide,
    pin: renderPin,
    reveal: renderImageReveal,
    scale: renderScale,
    slide: renderSlide,
    slider: renderSlider,
    text: renderText
  };
  function renderLiveResponse(question, context) {
    const renderer = RENDERERS[responseType(question)] || RENDERERS[question.qtype] || renderText;
    return renderer(question, context);
  }
  function questionSpeechText(question) {
    if (typeof question.speechText === "string" && question.speechText.trim() !== "") {
      return question.speechText;
    }
    const chunks = [question.questionText];
    if (question.qtype === "slide") {
      chunks.push(
        String(value(question, "title", "")),
        String(value(question, "body", "")),
        ...value(question, "bullets", []),
        String(value(question, "quote", "")),
        String(value(question, "attribution", ""))
      );
    }
    return chunks.filter(Boolean).join(". ");
  }
  function aggregateRecord(aggregate) {
    return aggregate && !Array.isArray(aggregate) && typeof aggregate === "object" ? aggregate : {};
  }
  function renderChoiceAggregate(question, entries, context) {
    const container = liveElement("div", "quizgeist-host-distribution", {
      "data-live-aggregate-kind": "choice",
      "data-live-distribution": true
    });
    const byChoice = new Map(entries.map((entry) => [entry.choiceId, entry]));
    question.choices.forEach((choice, index) => {
      var _a;
      const entry = byChoice.get(choice.id) || {
        choiceId: choice.id,
        count: 0,
        percent: 0
      };
      const slot = choiceSlot(question.qtype, index);
      const percent = Math.max(0, Math.min(100, Number(entry.percent || 0)));
      const row = liveElement("div", "quizgeist-host-distribution-row", {
        "data-live-choice-id": choice.id
      });
      const label = liveElement("div", "quizgeist-host-distribution-row__label");
      label.append(choiceShape(slot), liveElement("span", "", { text: choice.text }));
      const track = liveElement("div", "quizgeist-host-distribution-row__track");
      const bar = liveElement(
        "div",
        `quizgeist-host-distribution-row__bar quizgeist-host-distribution-row__bar--${slot}`,
        { text: `${Math.round(percent)} %` }
      );
      bar.style.width = `${percent}%`;
      track.append(bar);
      row.append(
        label,
        track,
        liveElement("span", "quizgeist-host-distribution-row__count", {
          text: String(Math.max(0, Number(entry.count || 0)))
        })
      );
      if (((_a = question.policyDescriptor) == null ? void 0 : _a.showsCorrectness) !== false && (entry.correct || choice.correct)) {
        row.classList.add("is-correct");
        row.append(liveElement("span", "quizgeist-host-distribution-row__correct", {
          text: context.text("host:reveal:correct", "Richtige Antwort")
        }));
      }
      container.append(row);
    });
    return container;
  }
  function objectRows(raw) {
    return Array.isArray(raw) ? raw.filter((entry) => Boolean(entry && typeof entry === "object" && !Array.isArray(entry))) : [];
  }
  function renderLiveAggregate(question, aggregate, context) {
    if (Array.isArray(aggregate)) {
      return renderChoiceAggregate(question, aggregate, context);
    }
    const record = aggregateRecord(aggregate);
    const kind = String(record.kind || (["quiz", "truefalse", "poll"].includes(question.qtype) ? "choice" : question.qtype));
    if (kind === "choice") {
      const entries = objectRows(record.entries || record.distribution).map((entry) => ({
        choiceId: String(entry.choiceId || entry.id || ""),
        correct: typeof entry.correct === "boolean" ? entry.correct : void 0,
        count: Number(entry.count || 0),
        percent: Number(entry.percent || 0)
      }));
      return renderChoiceAggregate(question, entries, context);
    }
    const root = liveElement(
      "section",
      `quizgeist-live-aggregate quizgeist-live-aggregate--${kind}`,
      { "data-live-aggregate-kind": kind }
    );
    if (kind === "wordcloud") {
      const cloud = liveElement("div", "quizgeist-live-wordcloud__cloud", {
        "data-live-wordcloud-published": true
      });
      objectRows(record.words || record.entries).forEach((word, index) => {
        const count = Math.max(1, Number(word.count || word.value || 1));
        const node = liveElement("span", "quizgeist-live-word", {
          "data-live-word": String(word.text || word.word || ""),
          text: String(word.text || word.word || "")
        });
        node.style.setProperty("--mq-word-weight", String(Math.min(6, 1 + Math.log2(count))));
        node.style.setProperty("--mq-word-slot", String(index % 6));
        node.append(liveElement("small", "", { text: ` ${count}` }));
        cloud.append(node);
      });
      if (cloud.childElementCount === 0) {
        cloud.append(liveElement("p", "quizgeist-live-aggregate__empty", {
          text: context.text(
            "live:wordcloud:nopublished",
            "Noch keine freigegebenen Begriffe."
          )
        }));
      }
      root.append(cloud);
      const moderation = record.moderation;
      if (context.audience === "host" && moderation && typeof moderation === "object" && !Array.isArray(moderation)) {
        const moderationRecord = moderation;
        const panel = liveElement("section", "quizgeist-live-wordcloud-moderation", {
          "aria-label": context.text(
            "live:wordcloud:moderation",
            "Begriffe moderieren"
          ),
          "data-live-wordcloud-moderation": true
        });
        panel.append(liveElement("h2", "quizgeist-live-wordcloud-moderation__title", {
          text: context.text("live:wordcloud:moderation", "Begriffe moderieren")
        }));
        const items = objectRows(moderationRecord.items);
        items.forEach((item) => {
          const key = String(item.key || "");
          const status = ["approved", "rejected"].includes(String(item.status)) ? String(item.status) : "pending";
          const row = liveElement("article", "quizgeist-live-wordcloud-moderation__item", {
            "data-live-wordcloud-status": status,
            "data-live-wordcloud-term-key": key
          });
          const term = liveElement("span", "quizgeist-live-wordcloud-moderation__term", {
            text: String(item.text || key)
          });
          term.append(liveElement("small", "", {
            text: ` ${Math.max(1, Number(item.count || 1))}`
          }));
          const statusLabel = liveElement(
            "span",
            "quizgeist-live-wordcloud-moderation__status",
            {
              text: context.text(
                `live:wordcloud:${status}`,
                status === "approved" ? "Freigegeben" : status === "rejected" ? "Abgelehnt" : "Ausstehend"
              )
            }
          );
          row.append(term, statusLabel);
          if (context.onAggregateAction) {
            const actions = liveElement(
              "div",
              "quizgeist-live-wordcloud-moderation__actions"
            );
            const approve = liveButton(
              context.text("live:wordcloud:approve", "Freigeben"),
              "quizgeist-host-button quizgeist-host-button--secondary",
              {
                "aria-busy": context.disabled ? "true" : "false",
                "aria-pressed": status === "approved" ? "true" : "false",
                "data-live-host-interaction": true,
                "data-live-wordcloud-moderate": "approved",
                "data-live-wordcloud-term-key": key,
                disabled: context.disabled || status === "approved"
              }
            );
            const reject = liveButton(
              context.text("live:wordcloud:reject", "Ablehnen"),
              "quizgeist-host-button quizgeist-host-button--secondary",
              {
                "aria-busy": context.disabled ? "true" : "false",
                "aria-pressed": status === "rejected" ? "true" : "false",
                "data-live-host-interaction": true,
                "data-live-wordcloud-moderate": "rejected",
                "data-live-wordcloud-term-key": key,
                disabled: context.disabled || status === "rejected"
              }
            );
            approve.addEventListener("click", () => {
              var _a;
              (_a = context.onAggregateAction) == null ? void 0 : _a.call(context, {
                decision: "approved",
                interactionKind: "moderation",
                termKey: key
              });
            });
            reject.addEventListener("click", () => {
              var _a;
              (_a = context.onAggregateAction) == null ? void 0 : _a.call(context, {
                decision: "rejected",
                interactionKind: "moderation",
                termKey: key
              });
            });
            actions.append(approve, reject);
            row.append(actions);
          }
          panel.append(row);
        });
        if (items.length === 0) {
          panel.append(liveElement("p", "quizgeist-live-aggregate__empty", {
            text: context.text("live:aggregate:empty", "Noch keine Antworten.")
          }));
        }
        root.append(panel);
      }
      return root;
    }
    if (kind === "scale" || kind === "slider") {
      const buckets = objectRows(record.histogram || record.buckets || record.entries);
      const chart = liveElement("div", "quizgeist-live-scale-aggregate");
      buckets.forEach((bucket) => {
        var _a, _b;
        const percent = Math.max(0, Math.min(100, Number(bucket.percent || 0)));
        const column = liveElement("div", "quizgeist-live-scale-aggregate__column", {
          "data-live-aggregate-value": String((_a = bucket.value) != null ? _a : "")
        });
        const bar = liveElement("span", "quizgeist-live-scale-aggregate__bar", {
          text: String(bucket.count || 0)
        });
        bar.style.height = `${Math.max(4, percent)}%`;
        column.append(bar, liveElement("strong", "", {
          text: String((_b = bucket.value) != null ? _b : "")
        }));
        chart.append(column);
      });
      root.append(chart);
      if (Number.isFinite(Number(record.mean))) {
        root.append(liveElement("p", "quizgeist-live-aggregate__mean", {
          text: `${context.text("live:aggregate:mean", "Mittelwert")}: ${Number(record.mean).toFixed(1)}`
        }));
      }
      if (Number.isFinite(Number(record.median))) {
        root.append(liveElement("p", "quizgeist-live-aggregate__median", {
          text: `${context.text("live:aggregate:median", "Median")}: ${Number(record.median).toFixed(1)}`
        }));
      }
      if (kind === "slider" && buckets.length === 0 && Array.isArray(record.values)) {
        const values = record.values.filter((entry) => typeof entry === "number" && Number.isFinite(entry));
        const valuesNode = liveElement("p", "quizgeist-live-aggregate__values", {
          "data-live-slider-values": true,
          text: values.join(" \xB7 ")
        });
        root.prepend(valuesNode);
      }
      return root;
    }
    if (kind === "pin") {
      const canvas = liveElement("div", "quizgeist-live-pin quizgeist-live-pin--aggregate", {
        "data-live-pin-heatmap": true
      });
      const image = createLiveMedia(pinMedia(question), {
        allowPlayback: false,
        className: "quizgeist-live-pin__image",
        label: question.questionText
      });
      if (image) {
        canvas.append(image);
      }
      objectRows(record.points || record.heatmap).forEach((point) => {
        const dot = liveElement("span", "quizgeist-live-pin__heat", { "aria-hidden": "true" });
        dot.style.setProperty("--mq-pin-weight", String(Number(point.weight || point.count || 1)));
        canvas.append(dot);
        positionPinNode(canvas, image instanceof HTMLImageElement ? image : null, dot, {
          x: Number(point.x || 0),
          y: Number(point.y || 0)
        });
      });
      const target = record.target;
      if (target && typeof target === "object") {
        const targetPoint = target;
        const marker = liveElement("span", "quizgeist-live-pin__target", {
          "aria-label": context.text("live:pin:target", "Zielbereich")
        });
        canvas.append(marker);
        positionPinNode(canvas, image instanceof HTMLImageElement ? image : null, marker, {
          x: Number(targetPoint.x || 0),
          y: Number(targetPoint.y || 0)
        });
      }
      if (image instanceof HTMLImageElement) {
        image.addEventListener("load", () => {
          objectRows(record.points || record.heatmap).forEach((point, index) => {
            const dot = canvas.querySelectorAll(".quizgeist-live-pin__heat")[index];
            if (dot) {
              positionPinNode(canvas, image, dot, {
                x: Number(point.x || 0),
                y: Number(point.y || 0)
              });
            }
          });
          const marker = canvas.querySelector(".quizgeist-live-pin__target");
          if (marker && target && typeof target === "object") {
            const targetPoint = target;
            positionPinNode(canvas, image, marker, {
              x: Number(targetPoint.x || 0),
              y: Number(targetPoint.y || 0)
            });
          }
        }, { once: true });
      }
      root.append(canvas);
      return root;
    }
    if (kind === "brainstorm") {
      const groups = objectRows(record.groups);
      groups.forEach((group) => {
        const section = liveElement("section", "quizgeist-live-brainstorm__group", {
          "data-live-brainstorm-group": String(group.key || "")
        });
        section.append(liveElement("h3", "", {
          text: String(group.label || "")
        }));
        if (group.votes !== null && group.votes !== void 0 && Number.isFinite(Number(group.votes))) {
          section.append(liveElement("strong", "quizgeist-live-brainstorm__votes", {
            text: `\u2665 ${Number(group.votes)}`
          }));
        }
        objectRows(group.ideas).forEach((idea) => {
          const ideaNode = liveElement("article", "quizgeist-live-brainstorm__idea", {
            "data-live-brainstorm-idea": String(idea.id || idea.key || ""),
            text: String(idea.text || "")
          });
          if (idea.votes !== null && idea.votes !== void 0 && Number.isFinite(Number(idea.votes))) {
            ideaNode.append(liveElement("span", "quizgeist-live-brainstorm__votes", {
              text: `\u2665 ${Number(idea.votes)}`
            }));
          }
          section.append(ideaNode);
        });
        root.append(section);
      });
      root.dataset.liveBrainstormStage = String(record.stage || "");
      const moderation = record.moderation;
      if (context.audience === "host" && moderation && typeof moderation === "object" && !Array.isArray(moderation)) {
        const moderationRecord = moderation;
        const panel = liveElement("section", "quizgeist-live-wordcloud-moderation", {
          "aria-label": context.text(
            "live:brainstorm:moderation",
            "Ideen moderieren"
          ),
          "data-live-brainstorm-moderation": true
        });
        panel.append(liveElement("h2", "quizgeist-live-wordcloud-moderation__title", {
          text: context.text("live:brainstorm:moderation", "Ideen moderieren")
        }));
        const items = objectRows(moderationRecord.items);
        items.forEach((item) => {
          const id = String(item.id || "");
          const status = ["approved", "rejected"].includes(String(item.status)) ? String(item.status) : "pending";
          const row = liveElement("article", "quizgeist-live-wordcloud-moderation__item", {
            "data-live-brainstorm-idea-id": id,
            "data-live-brainstorm-status": status
          });
          row.append(
            liveElement("span", "quizgeist-live-wordcloud-moderation__term", {
              text: String(item.text || "")
            }),
            liveElement("span", "quizgeist-live-wordcloud-moderation__status", {
              text: context.text(
                `live:wordcloud:${status}`,
                status === "approved" ? "Freigegeben" : status === "rejected" ? "Abgelehnt" : "Ausstehend"
              )
            })
          );
          if (context.onAggregateAction) {
            const actions = liveElement(
              "div",
              "quizgeist-live-wordcloud-moderation__actions"
            );
            const approve = liveButton(
              context.text("live:wordcloud:approve", "Freigeben"),
              "quizgeist-host-button quizgeist-host-button--secondary",
              {
                "aria-busy": context.disabled ? "true" : "false",
                "aria-pressed": status === "approved" ? "true" : "false",
                "data-live-brainstorm-moderate": "approved",
                "data-live-host-interaction": true,
                disabled: context.disabled || status === "approved"
              }
            );
            const reject = liveButton(
              context.text("live:wordcloud:reject", "Ablehnen"),
              "quizgeist-host-button quizgeist-host-button--secondary",
              {
                "aria-busy": context.disabled ? "true" : "false",
                "aria-pressed": status === "rejected" ? "true" : "false",
                "data-live-brainstorm-moderate": "rejected",
                "data-live-host-interaction": true,
                disabled: context.disabled || status === "rejected"
              }
            );
            approve.addEventListener("click", () => {
              var _a;
              (_a = context.onAggregateAction) == null ? void 0 : _a.call(context, {
                decision: "approved",
                ideaId: id,
                interactionKind: "moderation"
              });
            });
            reject.addEventListener("click", () => {
              var _a;
              (_a = context.onAggregateAction) == null ? void 0 : _a.call(context, {
                decision: "rejected",
                ideaId: id,
                interactionKind: "moderation"
              });
            });
            actions.append(approve, reject);
            row.append(actions);
          }
          panel.append(row);
        });
        if (items.length === 0) {
          panel.append(liveElement("p", "quizgeist-live-aggregate__empty", {
            text: context.text("live:aggregate:empty", "Noch keine Antworten.")
          }));
        }
        root.append(panel);
      }
      return root;
    }
    if (kind === "reactions") {
      const options = new Map(
        reactionOptions(question, context).map((option) => [option.id, option])
      );
      objectRows(record.counts || record.entries).forEach((entry) => {
        const key = String(entry.reaction || entry.id || "");
        const option = options.get(key);
        const badge = String(
          entry.emoji || (option == null ? void 0 : option.emoji) || (option == null ? void 0 : option.label) || REACTION_LABEL_FALLBACKS[key] || "\u2728"
        );
        root.append(liveElement("span", "quizgeist-live-reaction", {
          "data-live-reaction": key,
          text: `${badge} ${Number(entry.count || 0)}`
        }));
      });
      return root;
    }
    if (kind === "puzzle") {
      const positions = objectRows(record.positions);
      const order = Array.isArray(record.correctOrder) ? record.correctOrder : value(question, "correctOrderIds", []);
      const list = liveElement("ol", "quizgeist-live-puzzle");
      const ids = order.length > 0 ? order : positions.sort((left, right) => Number(left.position || 0) - Number(right.position || 0)).map((entry) => String(entry.itemId || entry.id || ""));
      ids.forEach((id) => {
        const item = value(question, "items", []).find((candidate) => candidate.id === id);
        const position = positions.find((entry) => String(entry.itemId || entry.id || "") === id);
        list.append(liveElement("li", "quizgeist-live-puzzle__item", {
          text: `${(item == null ? void 0 : item.text) || "Puzzle-Element"}${position ? ` \xB7 ${Number(position.correctPositionCount || 0)} richtig platziert` : ""}`
        }));
      });
      root.append(list);
      return root;
    }
    const responses = objectRows(record.responses || record.entries || record.answers);
    responses.forEach((entry) => {
      root.append(liveElement("blockquote", "quizgeist-live-text-response", {
        "data-live-text-response": String(entry.id || ""),
        text: String(entry.text || entry.answer || "")
      }));
    });
    if (responses.length === 0) {
      root.append(liveElement("p", "quizgeist-live-aggregate__empty", {
        text: context.text("live:aggregate:empty", "Noch keine Antworten.")
      }));
    }
    return root;
  }

  // src/live/sound-engine.ts
  var STORAGE_KEY = "mod_quizgeist:live-sound";
  var SoundEngine = class {
    constructor() {
      __publicField(this, "context", null);
      __publicField(this, "master", null);
      __publicField(this, "lobbyTimer", null);
      __publicField(this, "lobbyBeat", 0);
      __publicField(this, "muted", false);
      __publicField(this, "volume", 0.55);
      // F1: the activity-wide off switch. It is decided on the server and can
      // never be re-enabled from the client — unlike the personal mute button.
      __publicField(this, "allowed", true);
      try {
        const stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || "{}");
        this.muted = stored.muted === true;
        if (Number.isFinite(stored.volume)) {
          this.volume = Math.max(0, Math.min(1, Number(stored.volume)));
        }
      } catch (_error) {
      }
    }
    isMuted() {
      return this.muted || !this.allowed;
    }
    /**
     * Apply the activity-wide sound switch (F1 stress-free standard).
     *
     * With sounds disallowed the engine stays silent regardless of the
     * learner's personal setting, and the controls disappear.
     */
    setAllowed(allowed) {
      this.allowed = allowed;
      if (!allowed) {
        this.stopLobby();
      }
      this.applyGain();
    }
    isAllowed() {
      return this.allowed;
    }
    getVolume() {
      return this.volume;
    }
    async unlock() {
      if (!this.context) {
        const Constructor = window.AudioContext || window.webkitAudioContext;
        if (!Constructor) {
          return false;
        }
        this.context = new Constructor();
        this.master = this.context.createGain();
        this.master.connect(this.context.destination);
        this.applyGain();
      }
      if (this.context.state === "suspended") {
        try {
          await this.context.resume();
        } catch (_error) {
          return false;
        }
      }
      return this.context.state === "running";
    }
    setMuted(muted) {
      this.muted = muted;
      this.applyGain();
      this.persist();
    }
    setVolume(volume) {
      this.volume = Math.max(0, Math.min(1, volume));
      this.applyGain();
      this.persist();
    }
    startLobby() {
      if (this.lobbyTimer !== null || !this.context || !this.allowed) {
        return;
      }
      const beat = () => {
        if (!this.context || this.context.state !== "running") {
          return;
        }
        const chord = [261.63, 329.63, 392];
        chord.forEach((frequency, index) => {
          this.tone(frequency, 2.35, "triangle", 0.018, index * 0.025, 1.1);
        });
        if (this.lobbyBeat % 2 === 0) {
          const melody = [523.25, 587.33, 659.25, 783.99, 659.25, 587.33];
          this.tone(
            melody[this.lobbyBeat / 2 % melody.length],
            0.18,
            "sine",
            0.035,
            0.05,
            0.12
          );
        }
        this.lobbyBeat += 1;
      };
      beat();
      this.lobbyTimer = window.setInterval(beat, 2600);
    }
    stopLobby() {
      if (this.lobbyTimer !== null) {
        window.clearInterval(this.lobbyTimer);
        this.lobbyTimer = null;
      }
    }
    play(preset) {
      if (!this.allowed || !this.context || !this.master || this.context.state !== "running") {
        return;
      }
      switch (preset) {
        case "tap":
          this.tone(660, 0.035, "sine", 0.04, 0, 0.018);
          break;
        case "countdown":
          this.tone(220, 0.09, "square", 0.035, 0, 0.06, 1200);
          break;
        case "go":
          this.sweep(440, 660, 0.18, "square", 0.075, 1200);
          break;
        case "correct":
          [523.25, 659.25, 783.99, 1046.5].forEach((frequency, index) => {
            this.tone(frequency, 0.22, index % 2 ? "triangle" : "sine", 0.065, index * 0.09, 0.12);
          });
          break;
        case "incorrect":
          this.tone(329.63, 0.22, "sine", 0.045, 0, 0.15, 900);
          this.tone(261.63, 0.25, "sine", 0.04, 0.14, 0.15, 900);
          break;
        case "podium":
          [349.23, 392, 440, 523.25].forEach((root, index) => {
            [1, 1.25, 1.5].forEach((ratio) => {
              this.tone(root * ratio, index === 3 ? 0.7 : 0.36, "sawtooth", 0.03, index * 0.3, 0.32, 2500);
            });
          });
          break;
        case "streak":
          this.sweep(440, 880, 0.35, "triangle", 0.06);
          break;
        case "warning":
          this.tone(220, 0.06, "sine", 0.045, 0, 0.04);
          this.tone(220, 0.06, "sine", 0.045, 0.14, 0.04);
          break;
      }
    }
    destroy() {
      var _a;
      this.stopLobby();
      (_a = this.context) == null ? void 0 : _a.close().catch(() => void 0);
      this.context = null;
      this.master = null;
    }
    tone(frequency, duration, waveform, level, delay = 0, release = 0.1, lowpass = 0) {
      if (!this.context || !this.master) {
        return;
      }
      const start = this.context.currentTime + delay;
      const oscillator = this.context.createOscillator();
      const gain = this.context.createGain();
      oscillator.type = waveform;
      oscillator.frequency.setValueAtTime(frequency, start);
      gain.gain.setValueAtTime(1e-4, start);
      gain.gain.exponentialRampToValueAtTime(Math.max(1e-4, level), start + 8e-3);
      gain.gain.exponentialRampToValueAtTime(1e-4, start + duration + release);
      if (lowpass > 0) {
        const filter = this.context.createBiquadFilter();
        filter.type = "lowpass";
        filter.frequency.setValueAtTime(lowpass, start);
        oscillator.connect(filter);
        filter.connect(gain);
      } else {
        oscillator.connect(gain);
      }
      gain.connect(this.master);
      oscillator.start(start);
      oscillator.stop(start + duration + release + 0.02);
    }
    sweep(from, to, duration, waveform, level, lowpass = 0) {
      if (!this.context || !this.master) {
        return;
      }
      const start = this.context.currentTime;
      const oscillator = this.context.createOscillator();
      const gain = this.context.createGain();
      oscillator.type = waveform;
      oscillator.frequency.setValueAtTime(from, start);
      oscillator.frequency.exponentialRampToValueAtTime(to, start + duration);
      gain.gain.setValueAtTime(1e-4, start);
      gain.gain.exponentialRampToValueAtTime(level, start + 8e-3);
      gain.gain.exponentialRampToValueAtTime(1e-4, start + duration + 0.1);
      if (lowpass > 0) {
        const filter = this.context.createBiquadFilter();
        filter.type = "lowpass";
        filter.frequency.value = lowpass;
        oscillator.connect(filter);
        filter.connect(gain);
      } else {
        oscillator.connect(gain);
      }
      gain.connect(this.master);
      oscillator.start(start);
      oscillator.stop(start + duration + 0.12);
    }
    applyGain() {
      if (this.master && this.context) {
        this.master.gain.setTargetAtTime(
          this.isMuted() ? 0 : this.volume,
          this.context.currentTime,
          0.015
        );
      }
    }
    persist() {
      try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
          muted: this.muted,
          volume: this.volume
        }));
      } catch (_error) {
      }
    }
  };
  function createSoundControls(engine, strings) {
    const wrapper = liveElement("div", "quizgeist-live-sound", {
      "data-live-sound-controls": true
    });
    if (!engine.isAllowed()) {
      wrapper.hidden = true;
      return wrapper;
    }
    const muteLabel = strings["live:sound:mute"] || "Ton aus";
    const unmuteLabel = strings["live:sound:unmute"] || "Ton an";
    const mute = liveButton(
      engine.isMuted() ? unmuteLabel : muteLabel,
      "quizgeist-live-sound__mute",
      {
        "aria-pressed": engine.isMuted() ? "true" : "false",
        "data-live-sound-mute": true
      }
    );
    const volume = liveElement("input", "quizgeist-live-sound__volume", {
      "aria-label": strings["live:sound:label"] || "Lautst\xE4rke",
      "data-live-sound-volume": true,
      max: "100",
      min: "0",
      step: "5",
      type: "range",
      value: String(Math.round(engine.getVolume() * 100))
    });
    mute.addEventListener("click", () => {
      void engine.unlock();
      engine.setMuted(!engine.isMuted());
      mute.textContent = engine.isMuted() ? unmuteLabel : muteLabel;
      mute.setAttribute("aria-pressed", engine.isMuted() ? "true" : "false");
    });
    volume.addEventListener("input", () => {
      void engine.unlock();
      engine.setVolume(Number(volume.value) / 100);
      if (engine.getVolume() > 0 && engine.isMuted()) {
        engine.setMuted(false);
        mute.textContent = muteLabel;
        mute.setAttribute("aria-pressed", "false");
      }
    });
    wrapper.append(mute, volume);
    return wrapper;
  }

  // src/host/misconception-panel.ts
  var HINGE_STATUSES = [
    "insufficient",
    "reteach",
    "move_on"
  ];
  function normaliseHingeStatus(raw) {
    return typeof raw === "string" && HINGE_STATUSES.includes(raw) ? raw : null;
  }
  function normaliseMisconceptionLabel(raw) {
    return typeof raw === "string" && raw.trim() !== "" ? raw : null;
  }
  function renderHingeBadge(status, text2) {
    if (status === null) {
      return null;
    }
    const badge = liveElement("p", "quizgeist-hinge-badge", {
      "data-hinge-status": status,
      "data-quizgeist-view": "host-misconception",
      role: "status"
    });
    badge.append(
      liveElement("span", "quizgeist-hinge-badge__mark", {
        "aria-hidden": "true",
        text: status === "move_on" ? "\u25B2" : status === "reteach" ? "\u25A0" : "\xB7"
      }),
      liveElement("span", "quizgeist-hinge-badge__text", {
        text: hingeSentence(status, text2)
      })
    );
    return badge;
  }
  function hingeSentence(status, text2) {
    if (status === "move_on") {
      return text2(
        "host:hinge:moveon",
        "Genug verstanden \u2014 ihr k\xF6nnt weitergehen."
      );
    }
    if (status === "reteach") {
      return text2(
        "host:hinge:reteach",
        "Noch nicht sicher \u2014 diese Stelle lohnt eine Runde mehr."
      );
    }
    return text2(
      "host:hinge:insufficient",
      "Zu wenige Antworten f\xFCr eine Aussage."
    );
  }
  function renderMisconceptionLabel(label, text2) {
    if (label === null) {
      return null;
    }
    const node = liveElement("span", "quizgeist-misconception-label", {
      "data-misconception-label": true
    });
    node.append(
      liveElement("span", "quizgeist-misconception-label__lead", {
        text: text2("host:misconception:lead", "Fehlvorstellung:")
      }),
      // textContent, never innerHTML: the label is teacher input.
      liveElement("span", "quizgeist-misconception-label__text", { text: label })
    );
    return node;
  }
  function decorateDistribution(container, rows, status, text2) {
    container.querySelectorAll("[data-misconception-label]").forEach((node) => {
      node.remove();
    });
    rows.forEach((row) => {
      const label = normaliseMisconceptionLabel(row.label);
      if (label === null) {
        return;
      }
      const target = container.querySelector(
        `[data-live-choice-id="${cssEscape(row.choiceId)}"]`
      );
      const node = renderMisconceptionLabel(label, text2);
      if (target !== null && node !== null) {
        target.append(node);
      }
    });
    const existing = container.querySelector(".quizgeist-hinge-badge");
    if (existing !== null) {
      existing.remove();
    }
    const badge = renderHingeBadge(status, text2);
    if (badge !== null) {
      container.append(badge);
    }
  }
  function cssEscape(value2) {
    return value2.replace(/["\\]/g, "\\$&");
  }

  // src/host/card-scan.ts
  var MAX_EDGE = 1600;
  var UPLOAD_QUALITY = 0.85;
  var POLL_INTERVAL_MS = 1500;
  var MAX_POLLS = 60;
  function scanStateSentence(scan, text2) {
    if (scan === null) {
      return "";
    }
    const state = ["pending", "recognised", "confirmed", "failed", "discarded"].includes(scan.state) ? scan.state : "failed";
    const sentence = text2(
      `cards:state:${state}`,
      "Die Aufnahme wird verarbeitet."
    );
    const family = ["none", "unavailable", "unreadable", "gone", "unsupported", "failed"].includes(scan.reasonFamily) ? scan.reasonFamily : "failed";
    if (family === "none") {
      return sentence;
    }
    return `${sentence} ${text2(`cards:reason:${family}`, "")}`.trim();
  }
  async function downscaleFrame(source, width, height, maxEdge = MAX_EDGE) {
    const longest = Math.max(width, height);
    const scale = longest > maxEdge ? maxEdge / longest : 1;
    const targetWidth = Math.max(1, Math.round(width * scale));
    const targetHeight = Math.max(1, Math.round(height * scale));
    const canvas = document.createElement("canvas");
    canvas.width = targetWidth;
    canvas.height = targetHeight;
    const canvasContext = canvas.getContext("2d");
    if (canvasContext === null) {
      throw new Error("canvas_unavailable");
    }
    canvasContext.drawImage(source, 0, 0, targetWidth, targetHeight);
    return await new Promise((resolve, reject) => {
      canvas.toBlob(
        (blob) => {
          if (blob === null) {
            reject(new Error("encode_failed"));
            return;
          }
          resolve(blob);
        },
        "image/jpeg",
        UPLOAD_QUALITY
      );
    });
  }
  var CardScanPanel = class {
    constructor(context, target) {
      this.context = context;
      this.target = target;
      __publicField(this, "root");
      __publicField(this, "phase", "idle");
      __publicField(this, "cardSetId", 0);
      __publicField(this, "scan", null);
      __publicField(this, "stream", null);
      __publicField(this, "video", null);
      __publicField(this, "previewUrl", "");
      __publicField(this, "busyMessage", "");
      __publicField(this, "errorMessage", "");
      __publicField(this, "booked", null);
      __publicField(this, "skipped", []);
      __publicField(this, "corrections", /* @__PURE__ */ new Map());
      __publicField(this, "pollTimer", null);
      __publicField(this, "polls", 0);
      this.root = liveElement("section", "quizgeist-cardscan", {
        "data-quizgeist-view": "host-cardscan"
      });
      this.cardSetId = context.cardSets.length > 0 ? context.cardSets[0].id : 0;
      this.render();
    }
    /** The element the host app mounts. */
    element() {
      return this.root;
    }
    /** Point the panel at another question, discarding any open confirmation. */
    retarget(target) {
      if (target.sessionId === this.target.sessionId && target.questionId === this.target.questionId && target.visit === this.target.visit) {
        return;
      }
      this.target = target;
      this.reset();
    }
    /** Release the camera; the host app calls this when the screen goes away. */
    destroy() {
      this.stopPolling();
      this.stopCamera();
      this.releasePreview();
    }
    reset() {
      this.stopPolling();
      this.stopCamera();
      this.releasePreview();
      this.phase = "idle";
      this.scan = null;
      this.booked = null;
      this.skipped = [];
      this.errorMessage = "";
      this.busyMessage = "";
      this.corrections.clear();
      this.render();
    }
    text(key, fallback, values = {}) {
      return this.context.text(key, fallback, values);
    }
    render() {
      const nodes = [this.renderHeader()];
      if (this.phase === "camera") {
        nodes.push(this.renderCamera());
      }
      if (this.phase === "confirm" && this.scan !== null) {
        nodes.push(this.renderConfirm(this.scan));
      }
      nodes.push(this.renderStatus());
      this.root.replaceChildren(...nodes);
    }
    renderHeader() {
      var _a;
      const header = liveElement("div", "quizgeist-cardscan__intro");
      header.append(liveElement("h3", "quizgeist-cardscan__title", {
        text: this.text("cards:scan:title", "Karten scannen")
      }));
      if (this.context.cardSets.length === 0) {
        header.append(liveElement("p", "quizgeist-cardscan__empty", {
          text: this.text("cards:scan:noset", "Es ist noch kein Kartensatz angelegt.")
        }));
        return header;
      }
      header.append(liveElement("p", "quizgeist-cardscan__hint", {
        text: this.text("cards:scan:description", "")
      }));
      const selectId = `quizgeist-cardscan-set-${this.context.cmid}`;
      const label = liveElement("label", "quizgeist-cardscan__label", {
        for: selectId,
        // A visible label, never a placeholder: DESIGN 7.
        text: this.text("cards:scan:setlabel", "Kartensatz")
      });
      const select = liveElement("select", "quizgeist-cardscan__set quizgeist-touch-target", {
        id: selectId
      });
      for (const set of this.context.cardSets) {
        select.append(liveElement("option", "quizgeist-cardscan__option", {
          selected: set.id === this.cardSetId,
          text: `${set.name} (${set.cardCount})`,
          value: String(set.id)
        }));
      }
      select.disabled = this.phase !== "idle";
      select.addEventListener("change", () => {
        this.cardSetId = Number(select.value) || 0;
        this.render();
      });
      const chosen = (_a = this.context.cardSets.find((set) => set.id === this.cardSetId)) != null ? _a : this.context.cardSets[0];
      const setname = liveElement("p", "quizgeist-cardscan__setname", {
        text: `${chosen.name} \xB7 ${chosen.cardCount}`
      });
      const actions = liveElement("div", "quizgeist-cardscan__controls");
      actions.append(label, select);
      if (this.phase === "idle") {
        const start = liveButton(
          this.text("cards:scan:start", "Kamera \xF6ffnen"),
          "quizgeist-cardscan__start quizgeist-touch-target"
        );
        start.addEventListener("click", () => {
          void this.startCamera();
        });
        actions.append(start);
      }
      const print = liveElement("a", "quizgeist-cardscan__print quizgeist-touch-target", {
        href: `${this.context.printUrl}&cardset=${this.cardSetId}`,
        rel: "noopener",
        target: "_blank",
        text: this.text("cards:scan:print", "Kartenbogen drucken")
      });
      actions.append(print);
      header.append(actions, setname);
      return header;
    }
    renderCamera() {
      const frame = liveElement("div", "quizgeist-cardscan__camera");
      const video = liveElement("video", "quizgeist-cardscan__video", {
        "aria-label": this.text("cards:scan:preview", "Kameravorschau"),
        autoplay: true,
        muted: true,
        playsinline: true
      });
      video.muted = true;
      this.video = video;
      if (this.stream !== null) {
        video.srcObject = this.stream;
        void video.play().catch(() => {
        });
      }
      const controls = liveElement("div", "quizgeist-cardscan__controls");
      const capture = liveButton(
        this.text("cards:scan:capture", "Foto aufnehmen"),
        "quizgeist-cardscan__capture quizgeist-touch-target"
      );
      capture.addEventListener("click", () => {
        void this.capture();
      });
      const close = liveButton(
        this.text("cards:scan:close", "Kamera schlie\xDFen"),
        "quizgeist-cardscan__close quizgeist-touch-target"
      );
      close.addEventListener("click", () => {
        this.reset();
      });
      controls.append(capture, close);
      frame.append(video, controls);
      return frame;
    }
    renderStatus() {
      const status = liveElement("p", "quizgeist-cardscan__status", {
        "aria-atomic": "true",
        // The recognition state is announced, not only shown (8.3).
        "aria-live": "polite",
        role: "status"
      });
      if (this.errorMessage !== "") {
        status.textContent = this.errorMessage;
        status.classList.add("is-error");
        return status;
      }
      if (this.phase === "busy") {
        status.textContent = this.busyMessage;
        return status;
      }
      if (this.booked !== null) {
        const parts = [this.text("cards:scan:applied", "", { a: this.booked })];
        if (this.skipped.length > 0) {
          parts.push(this.text("cards:scan:skippedheading", ""));
          for (const entry of this.skipped) {
            parts.push(this.skipSentence(entry.code));
          }
        }
        status.textContent = parts.filter((part) => part !== "").join(" ");
        return status;
      }
      status.textContent = scanStateSentence(this.scan, this.context.text);
      return status;
    }
    skipSentence(code) {
      const known = [
        "card_without_player",
        "answer_unknown",
        "question_moved_on",
        "answer_too_late",
        "question_not_open",
        "booking_refused"
      ];
      const family = known.includes(code) ? code : "booking_refused";
      return this.text(`cards:skip:${family}`, "");
    }
    renderConfirm(scan) {
      const shell = liveElement("div", "quizgeist-cardconfirm", {
        "data-quizgeist-view": "host-cardconfirm"
      });
      shell.append(liveElement("p", "quizgeist-cardconfirm__progress", {
        "aria-live": "polite",
        text: this.text("cards:scan:progress", "", {
          expected: scan.expected,
          recognised: scan.recognised
        })
      }));
      if (scan.missing.length > 0) {
        shell.append(this.renderGroup(
          this.text("cards:scan:missingheading", "Nicht erkannt"),
          scan.missing.map((entry) => ({
            answerKey: "",
            bookable: entry.bookable,
            cardCode: entry.cardCode,
            confidence: -1,
            displayName: entry.displayName,
            source: "missing",
            userId: entry.userId
          })),
          "is-missing"
        ));
      }
      if (scan.entries.length > 0) {
        shell.append(this.renderGroup(
          this.text("cards:scan:recognisedheading", "Erkannt"),
          scan.entries,
          "is-recognised"
        ));
      }
      shell.append(liveElement("p", "quizgeist-cardconfirm__notice", {
        text: this.text("cards:scan:imagenotice", "")
      }));
      const actions = liveElement("div", "quizgeist-cardconfirm__actions");
      const apply = liveButton(
        this.text("cards:scan:apply", "\xDCbernehmen"),
        "quizgeist-cardconfirm__apply quizgeist-touch-target"
      );
      apply.addEventListener("click", () => {
        void this.confirm(false);
      });
      const discardButton = liveButton(
        this.text("cards:scan:discard", "Aufnahme verwerfen"),
        "quizgeist-cardconfirm__discard quizgeist-touch-target"
      );
      discardButton.addEventListener("click", () => {
        void this.confirm(true);
      });
      actions.append(apply, discardButton);
      shell.append(actions);
      return shell;
    }
    renderGroup(heading, entries, modifier) {
      const group = liveElement("div", `quizgeist-cardconfirm__group ${modifier}`);
      group.append(liveElement("h4", "quizgeist-cardconfirm__heading", { text: heading }));
      for (const entry of entries) {
        group.append(this.renderRow(entry));
      }
      return group;
    }
    renderRow(entry) {
      var _a;
      const row = liveElement("div", "quizgeist-cardconfirm__row", {
        "data-card-code": entry.cardCode
      });
      const name = liveElement("span", "quizgeist-cardconfirm__name", {
        text: entry.displayName
      });
      const selectId = `quizgeist-cardconfirm-${this.context.cmid}-${entry.cardCode}`;
      const label = liveElement("label", "quizgeist-cardconfirm__label", {
        for: selectId,
        text: this.text("cards:scan:answerlabel", "", { a: entry.displayName })
      });
      const select = liveElement("select", "quizgeist-cardconfirm__select quizgeist-touch-target", {
        id: selectId
      });
      const current = (_a = this.corrections.get(entry.cardCode)) != null ? _a : entry.answerKey;
      select.append(liveElement("option", "", {
        selected: current === "",
        text: this.text("cards:scan:nochoice", "keine Antwort"),
        value: ""
      }));
      for (const letter of this.target.letters) {
        select.append(liveElement("option", "", {
          selected: current === letter,
          text: letter,
          value: letter
        }));
      }
      select.addEventListener("change", () => {
        this.corrections.set(entry.cardCode, select.value);
      });
      row.append(label, name, select);
      if (entry.confidence >= 0 && entry.source === "model") {
        row.append(liveElement("span", "quizgeist-cardconfirm__confidence", {
          text: this.text("cards:scan:confidence", "", {
            a: Math.round(entry.confidence * 100)
          })
        }));
      } else {
        row.append(liveElement("span", "quizgeist-cardconfirm__confidence", {
          text: this.text("cards:scan:notrecognised", "nicht erkannt")
        }));
      }
      if (!entry.bookable) {
        row.classList.add("is-unbookable");
        row.append(liveElement("span", "quizgeist-cardconfirm__unbookable", {
          text: this.text("cards:scan:unbookable", "")
        }));
      }
      return row;
    }
    async startCamera() {
      this.errorMessage = "";
      const media = navigator.mediaDevices;
      if (media === void 0 || typeof media.getUserMedia !== "function") {
        this.errorMessage = this.text("cards:scan:cameraunavailable", "");
        this.render();
        return;
      }
      try {
        this.stream = await media.getUserMedia({
          audio: false,
          video: { facingMode: "environment" }
        });
      } catch (_error) {
        this.errorMessage = this.text("cards:scan:camerablocked", "");
        this.render();
        return;
      }
      this.phase = "camera";
      this.render();
    }
    stopCamera() {
      if (this.stream !== null) {
        for (const track of this.stream.getTracks()) {
          track.stop();
        }
        this.stream = null;
      }
      if (this.video !== null) {
        this.video.srcObject = null;
        this.video = null;
      }
    }
    releasePreview() {
      if (this.previewUrl !== "") {
        URL.revokeObjectURL(this.previewUrl);
        this.previewUrl = "";
      }
    }
    async capture() {
      var _a, _b;
      const video = this.video;
      if (video === null || this.cardSetId <= 0) {
        return;
      }
      const width = video.videoWidth || 0;
      const height = video.videoHeight || 0;
      if (width <= 0 || height <= 0) {
        this.errorMessage = this.text("cards:scan:cameraunavailable", "");
        this.render();
        return;
      }
      this.phase = "busy";
      this.busyMessage = this.text("cards:scan:uploading", "");
      this.render();
      let blob;
      try {
        blob = await downscaleFrame(video, width, height);
      } catch (_error) {
        this.fail(this.text("cards:error:scanuploadfailed", ""));
        return;
      }
      this.releasePreview();
      this.previewUrl = URL.createObjectURL(blob);
      this.stopCamera();
      const form = new FormData();
      form.append("id", String(this.context.cmid));
      form.append("sesskey", this.context.sesskey);
      form.append("kind", "cardscan");
      form.append("sessionId", String(this.target.sessionId));
      form.append("questionId", String(this.target.questionId));
      form.append("visit", this.target.visit);
      form.append("cardSetId", String(this.cardSetId));
      form.append("scan", blob, "cardscan.jpg");
      let payload = null;
      let ok = false;
      try {
        const response = await fetch(this.context.uploadUrl, {
          body: form,
          credentials: "same-origin",
          headers: { Accept: "application/json" },
          method: "POST"
        });
        ok = response.ok;
        payload = await response.json().catch(() => null);
      } catch (_error) {
        this.fail(this.text("cards:error:scanuploadfailed", ""));
        return;
      }
      if (!ok || payload === null || payload.scan === void 0) {
        this.fail((_b = (_a = payload == null ? void 0 : payload.error) == null ? void 0 : _a.message) != null ? _b : this.text("cards:error:scanuploadfailed", ""));
        return;
      }
      this.scan = payload.scan;
      this.busyMessage = this.text("cards:scan:recognising", "");
      this.render();
      await this.recognise();
    }
    async recognise() {
      const scan = this.scan;
      if (scan === null) {
        return;
      }
      try {
        const result = await this.context.api.post(
          "card_scan_recognise",
          { scanId: scan.id }
        );
        this.applyScan(result.scan);
      } catch (error) {
        this.startPolling();
        if (error instanceof Error && error.message !== "") {
          this.busyMessage = this.text("cards:scan:recognising", "");
        }
      }
    }
    applyScan(scan) {
      this.scan = scan;
      if (scan.state === "pending") {
        this.startPolling();
        this.phase = "busy";
        this.busyMessage = this.text("cards:scan:recognising", "");
        this.render();
        return;
      }
      this.stopPolling();
      this.phase = scan.state === "recognised" ? "confirm" : "idle";
      this.render();
    }
    startPolling() {
      if (this.pollTimer !== null) {
        return;
      }
      this.polls = 0;
      this.pollTimer = window.setInterval(() => {
        void this.poll();
      }, POLL_INTERVAL_MS);
    }
    stopPolling() {
      if (this.pollTimer !== null) {
        window.clearInterval(this.pollTimer);
        this.pollTimer = null;
      }
    }
    async poll() {
      const scan = this.scan;
      if (scan === null) {
        this.stopPolling();
        return;
      }
      this.polls += 1;
      if (this.polls > MAX_POLLS) {
        this.stopPolling();
        this.fail(this.text("cards:reason:failed", ""));
        return;
      }
      try {
        const result = await this.context.api.post(
          "card_scan_state",
          { scanId: scan.id }
        );
        if (result.scan.state !== "pending") {
          this.applyScan(result.scan);
        }
      } catch (_error) {
      }
    }
    async confirm(discard) {
      const scan = this.scan;
      if (scan === null) {
        return;
      }
      this.phase = "busy";
      this.busyMessage = this.text("cards:scan:uploading", "");
      this.render();
      const corrections = [];
      for (const [cardCode, answerKey] of this.corrections.entries()) {
        corrections.push({ answerKey: answerKey === "" ? null : answerKey, cardCode });
      }
      try {
        const result = await this.context.api.post("card_scan_confirm", { corrections, discard, scanId: scan.id });
        this.scan = result.scan;
        this.booked = discard ? null : result.booked;
        this.skipped = Array.isArray(result.skipped) ? result.skipped : [];
        this.corrections.clear();
        this.releasePreview();
        this.phase = "idle";
        this.render();
      } catch (error) {
        this.fail(error instanceof Error && error.message !== "" ? error.message : this.text("cards:error:scanuploadfailed", ""));
      }
    }
    fail(message) {
      this.stopPolling();
      this.stopCamera();
      this.phase = "idle";
      this.errorMessage = message;
      this.render();
    }
  };

  // src/live/strings.ts
  var MISSING_STRING_PLACEHOLDER = "\u2026";
  var reportedKeys = /* @__PURE__ */ new Set();
  function reportMissingString(key) {
    if (key === "" || reportedKeys.has(key)) {
      return;
    }
    reportedKeys.add(key);
    if (typeof console !== "undefined" && typeof console.warn === "function") {
      console.warn(
        `[quizgeist] Fehlender Sprachschl\xFCssel: ${key}. Bitte in view.php ausliefern und in lang/de sowie lang/en erg\xE4nzen.`
      );
    }
  }
  function liveString(strings, key, values = {}, fallback = "") {
    const configured = strings[key];
    let template;
    if (typeof configured === "string" && configured !== "") {
      template = configured;
    } else {
      reportMissingString(key);
      template = fallback !== "" ? fallback : MISSING_STRING_PLACEHOLDER;
    }
    return template.replace(/\{\$a->([a-zA-Z0-9_]+)\}/g, (_match, name) => {
      const value2 = values[name];
      return value2 === void 0 ? "" : String(value2);
    }).replace(/\{\$a\}/g, () => {
      const value2 = values.a;
      return value2 === void 0 ? "" : String(value2);
    });
  }

  // src/live/tts.ts
  function text(config, key, fallback) {
    var _a, _b;
    const editorAlias = {
      "live:tts:error": "editor:tts:error",
      "live:tts:play": "editor:tts:play",
      "live:tts:stop": "editor:tts:loading",
      "live:tts:unavailable": "editor:tts:unavailable"
    };
    return ((_a = config.strings) == null ? void 0 : _a[key]) || ((_b = config.strings) == null ? void 0 : _b[editorAlias[key]]) || fallback;
  }
  var MAX_TTS_CHUNK_CODEPOINTS = 600;
  function chunkTtsText(content, maximum = MAX_TTS_CHUNK_CODEPOINTS) {
    if (!Number.isInteger(maximum) || maximum < 1) {
      throw new RangeError("The TTS chunk size must be a positive integer.");
    }
    const chunks = [];
    let remaining = Array.from(content.trim());
    while (remaining.length > 0) {
      if (remaining.length <= maximum) {
        const finalChunk = remaining.join("").trim();
        if (finalChunk !== "") {
          chunks.push(finalChunk);
        }
        break;
      }
      const minimumPreferredBoundary = Math.max(1, Math.floor(maximum * 0.45));
      let sentenceBoundary = 0;
      let whitespaceBoundary = 0;
      for (let index = maximum; index >= minimumPreferredBoundary; index -= 1) {
        const previous = remaining[index - 1] || "";
        const next = remaining[index] || "";
        if (sentenceBoundary === 0 && (previous === "." || previous === "!" || previous === "?" || previous === "\n") && (next === "" || /\s/u.test(next))) {
          sentenceBoundary = index;
        }
        if (whitespaceBoundary === 0 && /\s/u.test(previous)) {
          whitespaceBoundary = index - 1;
        }
        if (sentenceBoundary > 0 && whitespaceBoundary > 0) {
          break;
        }
      }
      const cut = sentenceBoundary || whitespaceBoundary || maximum;
      const chunk = remaining.slice(0, cut).join("").trim();
      if (chunk !== "") {
        chunks.push(chunk);
      }
      remaining = remaining.slice(cut);
      while (remaining.length > 0 && /\s/u.test(remaining[0])) {
        remaining.shift();
      }
    }
    return chunks;
  }
  var TtsPlayer = class {
    constructor(config) {
      this.config = config;
      __publicField(this, "audio", null);
      __publicField(this, "controller", null);
      __publicField(this, "currentPlaybackReject", null);
      __publicField(this, "objectUrl", null);
      __publicField(this, "playbackGeneration", 0);
      __publicField(this, "playing", false);
    }
    isAvailable() {
      var _a;
      return Boolean(
        ((_a = this.config.tts) == null ? void 0 : _a.available) && this.config.tts.speakUrl && this.config.tts.voices.length > 0 && this.config.sesskey
      );
    }
    isPlaying() {
      return this.playing;
    }
    stop() {
      var _a;
      this.playbackGeneration += 1;
      this.playing = false;
      (_a = this.controller) == null ? void 0 : _a.abort();
      this.controller = null;
      const reject = this.currentPlaybackReject;
      this.currentPlaybackReject = null;
      this.releaseAudio();
      reject == null ? void 0 : reject(new DOMException("Playback stopped.", "AbortError"));
    }
    releaseAudio() {
      if (this.audio) {
        this.audio.pause();
        this.audio.currentTime = 0;
        this.audio = null;
      }
      if (this.objectUrl) {
        URL.revokeObjectURL(this.objectUrl);
        this.objectUrl = null;
      }
    }
    async play(content, voiceId) {
      var _a, _b, _c;
      this.stop();
      const chunks = chunkTtsText(content);
      if (!this.isAvailable() || chunks.length === 0) {
        throw new Error(text(
          this.config,
          "live:tts:unavailable",
          "Vorlesen ist f\xFCr dieses Nutzerkonto nicht verf\xFCgbar."
        ));
      }
      const selectedVoice = voiceId || ((_a = this.config.tts) == null ? void 0 : _a.defaultVoiceId) || ((_c = (_b = this.config.tts) == null ? void 0 : _b.voices[0]) == null ? void 0 : _c.id) || 0;
      const generation = this.playbackGeneration;
      this.playing = true;
      try {
        for (const chunk of chunks) {
          if (generation !== this.playbackGeneration) {
            throw new DOMException("Playback stopped.", "AbortError");
          }
          const blob = await this.requestAudio(chunk, selectedVoice, generation);
          await this.playAudio(blob, generation);
        }
      } finally {
        if (generation === this.playbackGeneration) {
          this.playing = false;
          this.controller = null;
          this.currentPlaybackReject = null;
          this.releaseAudio();
        }
      }
    }
    async requestAudio(value2, selectedVoice, generation) {
      var _a;
      const form = new FormData();
      form.append("action", "speak");
      form.append("sesskey", String(this.config.sesskey || ""));
      form.append("text", value2);
      form.append("voiceid", String(selectedVoice));
      form.append("speed", "0.9");
      const controller = new AbortController();
      this.controller = controller;
      const response = await fetch(String(((_a = this.config.tts) == null ? void 0 : _a.speakUrl) || ""), {
        method: "POST",
        credentials: "same-origin",
        body: form,
        signal: controller.signal
      });
      if (generation !== this.playbackGeneration) {
        throw new DOMException("Playback stopped.", "AbortError");
      }
      const contentType = (response.headers.get("Content-Type") || "").split(";", 1)[0].trim().toLowerCase();
      if (!response.ok || contentType !== "audio/mpeg" && contentType !== "audio/mp3") {
        this.controller = null;
        throw new Error(text(
          this.config,
          "live:tts:error",
          "Der Text konnte nicht vorgelesen werden."
        ));
      }
      const blob = await response.blob();
      this.controller = null;
      if (blob.size === 0) {
        throw new Error(text(
          this.config,
          "live:tts:error",
          "Der Text konnte nicht vorgelesen werden."
        ));
      }
      return blob;
    }
    async playAudio(blob, generation) {
      this.objectUrl = URL.createObjectURL(blob);
      this.audio = new Audio(this.objectUrl);
      await new Promise((resolve, reject) => {
        var _a, _b, _c;
        let settled = false;
        const finish = (error) => {
          if (settled) {
            return;
          }
          settled = true;
          this.currentPlaybackReject = null;
          this.releaseAudio();
          if (error) {
            reject(error);
          } else {
            resolve();
          }
        };
        this.currentPlaybackReject = (reason) => finish(reason);
        (_a = this.audio) == null ? void 0 : _a.addEventListener("ended", () => finish(), { once: true });
        (_b = this.audio) == null ? void 0 : _b.addEventListener(
          "error",
          () => finish(new Error(text(
            this.config,
            "live:tts:error",
            "Der Text konnte nicht vorgelesen werden."
          ))),
          { once: true }
        );
        (_c = this.audio) == null ? void 0 : _c.play().catch((error) => finish(
          error instanceof Error ? error : new Error(text(
            this.config,
            "live:tts:error",
            "Der Text konnte nicht vorgelesen werden."
          ))
        ));
      });
      if (generation !== this.playbackGeneration) {
        throw new DOMException("Playback stopped.", "AbortError");
      }
    }
  };
  function createTtsControl(player, content, config) {
    const wrapper = liveElement("div", "quizgeist-live-tts", {
      "data-live-tts": true
    });
    const status = liveElement("span", "quizgeist-live-tts__status", {
      "aria-live": "polite",
      "data-live-tts-status": true
    });
    const playLabel = text(config, "live:tts:play", "Vorlesen");
    const stopLabel = text(config, "live:tts:stop", "Stoppen");
    const button = liveButton(
      playLabel,
      "quizgeist-live-tts__button",
      {
        "aria-pressed": "false",
        "data-live-tts-toggle": true,
        disabled: !player.isAvailable() || content.trim() === ""
      }
    );
    if (button.disabled) {
      button.title = text(
        config,
        "live:tts:unavailable",
        "Vorlesen ist f\xFCr dieses Nutzerkonto nicht verf\xFCgbar."
      );
    }
    button.addEventListener("click", () => {
      if (player.isPlaying()) {
        player.stop();
        button.textContent = playLabel;
        button.setAttribute("aria-pressed", "false");
        status.textContent = "";
        return;
      }
      button.textContent = stopLabel;
      button.setAttribute("aria-pressed", "true");
      status.textContent = stopLabel;
      void player.play(content).catch((error) => {
        if (!(error instanceof DOMException && error.name === "AbortError")) {
          status.textContent = error instanceof Error ? error.message : text(config, "live:tts:error", "Der Text konnte nicht vorgelesen werden.");
        }
      }).finally(() => {
        button.textContent = playLabel;
        button.setAttribute("aria-pressed", "false");
        if (status.textContent === stopLabel) {
          status.textContent = "";
        }
      });
    });
    wrapper.append(button, status);
    return wrapper;
  }

  // src/host/conflict-retry.ts
  var HOST_MUTATION_MAX_ATTEMPTS = 3;
  var HOST_MUTATION_RETRY_DELAYS_MS = [60, 120];
  function hostPosition(state) {
    var _a, _b, _c;
    return {
      currentIndex: state.currentIndex,
      interactionStage: (_a = state.interactionStage) != null ? _a : null,
      phase: state.phase,
      questionToken: (_c = (_b = state.question) == null ? void 0 : _b.questionToken) != null ? _c : null,
      sessionId: state.sessionId
    };
  }
  function isSameHostPosition(expected, fresh) {
    const left = hostPosition(expected);
    const right = hostPosition(fresh);
    return left.sessionId === right.sessionId && left.phase === right.phase && left.currentIndex === right.currentIndex && left.questionToken === right.questionToken && left.interactionStage === right.interactionStage;
  }
  function waitForRetry(delayMs) {
    return new Promise((resolve) => {
      globalThis.setTimeout(resolve, delayMs);
    });
  }
  async function retryHostMutation(options) {
    var _a, _b;
    const requestedAttempts = (_a = options.maxAttempts) != null ? _a : HOST_MUTATION_MAX_ATTEMPTS;
    const maxAttempts = Number.isFinite(requestedAttempts) && requestedAttempts >= 1 ? Math.floor(requestedAttempts) : HOST_MUTATION_MAX_ATTEMPTS;
    const wait = (_b = options.wait) != null ? _b : waitForRetry;
    let expectedState = options.initialState;
    for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
      try {
        return {
          status: "success",
          value: await options.request(expectedState)
        };
      } catch (error) {
        const fresh = options.conflictState(error);
        if (!fresh) {
          throw error;
        }
        options.applyConflictState(fresh);
        if (!isSameHostPosition(expectedState, fresh)) {
          return { error, status: "position-changed" };
        }
        if (attempt + 1 >= maxAttempts) {
          return { error, status: "conflict-exhausted" };
        }
        const delay = HOST_MUTATION_RETRY_DELAYS_MS[Math.min(attempt, HOST_MUTATION_RETRY_DELAYS_MS.length - 1)];
        await wait(delay);
        const current = options.currentState();
        if (!current || !isSameHostPosition(fresh, current)) {
          return { error, status: "position-changed" };
        }
        expectedState = current.stateVersion >= fresh.stateVersion ? current : fresh;
      }
    }
    throw new Error("Host mutation retry ended without a result.");
  }

  // src/live/stage-mode.ts
  var StageModeController = class {
    constructor(root, hooks, options = {}) {
      this.root = root;
      this.hooks = hooks;
      __publicField(this, "document");
      __publicField(this, "available");
      __publicField(this, "options");
      __publicField(this, "exactRoot");
      __publicField(this, "focusOnlyOwnChange");
      __publicField(this, "avoidInputFocus");
      __publicField(this, "toggleButton", null);
      __publicField(this, "attached", false);
      __publicField(this, "_mode", "off");
      __publicField(this, "rootSessionActive", false);
      __publicField(this, "pendingEnter", false);
      __publicField(this, "pendingLeave", false);
      __publicField(this, "ownEnterPending", false);
      __publicField(this, "ownLeavePending", false);
      __publicField(this, "leaveAfterEnter", false);
      __publicField(this, "handleFullscreenChange", () => {
        this.syncFromDocument();
      });
      __publicField(this, "handleFullscreenError", () => {
        if (this.pendingEnter && !this.isActive()) {
          this.failPendingEnter();
          return;
        }
        if (this.pendingLeave) {
          this.pendingLeave = false;
          this.ownLeavePending = false;
        }
      });
      var _a, _b, _c;
      this.options = options;
      this.exactRoot = (_a = options.exactRoot) != null ? _a : false;
      this.focusOnlyOwnChange = (_b = options.focusOnlyOwnChange) != null ? _b : false;
      this.avoidInputFocus = (_c = options.avoidInputFocus) != null ? _c : false;
      this.document = root.ownerDocument;
      const fullscreenRoot = root;
      this.available = (this.document.fullscreenEnabled === true || this.document.webkitFullscreenEnabled === true) && (typeof root.requestFullscreen === "function" || typeof fullscreenRoot.webkitRequestFullscreen === "function");
    }
    mode() {
      return this._mode;
    }
    isAvailable() {
      return this.available;
    }
    createToggle() {
      var _a, _b, _c, _d, _e;
      const label = this.hooks.text(
        (_a = this.options.toggleKey) != null ? _a : "host:stagemode:toggle",
        (_b = this.options.toggleFallback) != null ? _b : "Vollbild"
      );
      const button = this.document.createElement("button");
      button.type = "button";
      button.className = (_c = this.options.buttonClassName) != null ? _c : "quizgeist-host-button quizgeist-host-button--secondary quizgeist-host-toolbar__stagemode";
      button.textContent = label;
      button.title = this.options.titleKey !== void 0 || this.options.titleFallback !== void 0 ? this.hooks.text(
        (_d = this.options.titleKey) != null ? _d : "host:stagemode:title",
        (_e = this.options.titleFallback) != null ? _e : "Vollbild ein- und ausschalten"
      ) : label === "Vollbild" ? "Vollbild ein- und ausschalten" : label;
      button.setAttribute("aria-pressed", this._mode === "fullscreen" ? "true" : "false");
      button.hidden = !this.available;
      button.addEventListener("click", () => {
        void this.toggle();
      });
      this.toggleButton = button;
      return button;
    }
    attach() {
      if (this.attached) {
        return;
      }
      this.attached = true;
      this.document.addEventListener("fullscreenchange", this.handleFullscreenChange);
      this.document.addEventListener("webkitfullscreenchange", this.handleFullscreenChange);
      this.document.addEventListener("fullscreenerror", this.handleFullscreenError);
    }
    detach() {
      if (!this.attached) {
        return;
      }
      this.document.removeEventListener("fullscreenchange", this.handleFullscreenChange);
      this.document.removeEventListener("webkitfullscreenchange", this.handleFullscreenChange);
      this.document.removeEventListener("fullscreenerror", this.handleFullscreenError);
      this.attached = false;
    }
    async enter() {
      var _a;
      if (!this.available || this.isActive()) {
        return;
      }
      this.pendingEnter = true;
      this.ownEnterPending = true;
      this.leaveAfterEnter = false;
      const fullscreenRoot = this.root;
      try {
        const request = typeof this.root.requestFullscreen === "function" ? this.root.requestFullscreen() : (_a = fullscreenRoot.webkitRequestFullscreen) == null ? void 0 : _a.call(fullscreenRoot);
        if (request && typeof request.then === "function") {
          await Promise.resolve(request);
          this.pendingEnter = false;
          if (this.leaveAfterEnter && this.isActive()) {
            void this.leave();
          }
        } else {
          await this.nextFrame();
          if (!this.isActive()) {
            this.failPendingEnter();
          } else {
            this.pendingEnter = false;
            if (this.leaveAfterEnter) {
              void this.leave();
            }
          }
        }
      } catch (_error) {
        this.failPendingEnter();
      }
    }
    async leave() {
      if (!this.isActive()) {
        if (this.pendingEnter || this.ownEnterPending) {
          this.leaveAfterEnter = true;
          this.ownLeavePending = true;
        }
        return;
      }
      this.pendingLeave = true;
      this.ownLeavePending = true;
      this.leaveAfterEnter = false;
      try {
        const exit = this.document.exitFullscreen || this.document.webkitExitFullscreen;
        if (!exit) {
          this.pendingLeave = false;
          this.ownLeavePending = false;
          return;
        }
        const result = exit.call(this.document);
        if (result && typeof result.then === "function") {
          await Promise.resolve(result);
          this.pendingLeave = false;
        } else {
          await this.nextFrame();
          this.pendingLeave = false;
        }
      } catch (_error) {
        this.pendingLeave = false;
        this.ownLeavePending = false;
      }
    }
    async toggle() {
      if (this.isActive()) {
        await this.leave();
      } else {
        await this.enter();
      }
    }
    fullscreenElement() {
      var _a, _b;
      return (_b = (_a = this.document.fullscreenElement) != null ? _a : this.document.webkitFullscreenElement) != null ? _b : null;
    }
    isActive() {
      const fullscreen = this.fullscreenElement();
      if (fullscreen === this.root) {
        return true;
      }
      return !this.exactRoot && fullscreen instanceof Node && this.root.contains(fullscreen);
    }
    async nextFrame() {
      await new Promise((resolve) => {
        var _a;
        const requestAnimationFrame = (_a = this.document.defaultView) == null ? void 0 : _a.requestAnimationFrame;
        if (requestAnimationFrame) {
          requestAnimationFrame(() => resolve());
        } else {
          resolve();
        }
      });
    }
    failPendingEnter() {
      var _a, _b;
      if (!this.pendingEnter) {
        return;
      }
      this.pendingEnter = false;
      this.ownEnterPending = false;
      this.ownLeavePending = false;
      this.leaveAfterEnter = false;
      if (this.isActive()) {
        return;
      }
      this.rootSessionActive = false;
      this.setMode("off");
      this.hooks.announce(this.hooks.text(
        (_a = this.options.offKey) != null ? _a : "host:stagemode:off",
        (_b = this.options.offFallback) != null ? _b : "Vollbild ausgeschaltet."
      ));
    }
    syncFromDocument() {
      var _a, _b, _c, _d;
      const fullscreen = this.fullscreenElement();
      const rootFullscreen = fullscreen === this.root;
      const active = this.isActive();
      const previousMode = this._mode;
      const nextMode = active ? "fullscreen" : "off";
      const ownEnter = this.ownEnterPending;
      const ownLeave = this.ownLeavePending;
      this.setMode(nextMode);
      if (rootFullscreen && active) {
        const enteredRoot = !this.rootSessionActive;
        this.rootSessionActive = true;
        this.pendingEnter = false;
        this.pendingLeave = false;
        this.ownEnterPending = false;
        this.ownLeavePending = false;
        if (this.leaveAfterEnter) {
          this.leaveAfterEnter = false;
          void this.leave();
          return;
        }
        if (enteredRoot && previousMode === "off") {
          this.announceAndFocus(
            (_a = this.options.onKey) != null ? _a : "host:stagemode:on",
            (_b = this.options.onFallback) != null ? _b : "Vollbild eingeschaltet.",
            ownEnter
          );
        }
        return;
      }
      if (active) {
        this.pendingEnter = false;
        this.ownEnterPending = false;
        return;
      }
      this.pendingEnter = false;
      this.pendingLeave = false;
      this.ownEnterPending = false;
      this.leaveAfterEnter = false;
      if (previousMode === "fullscreen" && this.rootSessionActive) {
        this.rootSessionActive = false;
        this.ownLeavePending = false;
        this.announceAndFocus(
          (_c = this.options.offKey) != null ? _c : "host:stagemode:off",
          (_d = this.options.offFallback) != null ? _d : "Vollbild ausgeschaltet.",
          ownLeave
        );
      } else {
        this.rootSessionActive = false;
        this.ownLeavePending = false;
      }
    }
    announceAndFocus(key, fallback, ownChange) {
      var _a;
      this.hooks.announce(this.hooks.text(key, fallback));
      if (this.focusOnlyOwnChange && !ownChange) {
        return;
      }
      if (this.avoidInputFocus && this.isEditableFocusTarget()) {
        return;
      }
      if (((_a = this.toggleButton) == null ? void 0 : _a.isConnected) && !this.toggleButton.hidden) {
        this.toggleButton.focus();
        return;
      }
      if (ownChange) {
        this.focusFallbackTarget();
      }
    }
    updateToggle() {
      if (!this.toggleButton) {
        return;
      }
      this.toggleButton.setAttribute("aria-pressed", this._mode === "fullscreen" ? "true" : "false");
    }
    setMode(mode) {
      var _a, _b;
      const changed = this._mode !== mode;
      this._mode = mode;
      this.updateToggle();
      if (changed) {
        (_b = (_a = this.options).onModeChange) == null ? void 0 : _b.call(_a, mode);
      }
    }
    isEditableFocusTarget() {
      const active = this.document.activeElement;
      if (!(active instanceof HTMLElement)) {
        return false;
      }
      if (active.isContentEditable) {
        return true;
      }
      const tagName = active.tagName.toLowerCase();
      return tagName === "input" || tagName === "textarea" || tagName === "select" || active.closest("[contenteditable]") !== null;
    }
    focusFallbackTarget() {
      const fallback = this.options.focusFallback;
      if (!fallback) {
        return;
      }
      fallback();
    }
  };

  // src/host/host-app.ts
  var ACTIVE_PHASES = /* @__PURE__ */ new Set([
    "lobby",
    "question",
    "reveal",
    "scoreboard",
    "podium"
  ]);
  var CONFETTI_COLORS = ["a", "b", "c", "d", "e"];
  var HostApp = class {
    constructor(root, config) {
      this.root = root;
      this.config = config;
      __publicField(this, "api");
      __publicField(this, "poller");
      /**
       * F11a: exists only while a question is on screen AND the AI addon shipped a
       * card configuration. Held on the app rather than rebuilt per render so a
       * 1–2 s poll cannot tear the camera down under the teacher's thumb.
       */
      __publicField(this, "cardScan", null);
      __publicField(this, "reconnectKey");
      __publicField(this, "bootstrapController", null);
      __publicField(this, "clockOffsetMs", 0);
      __publicField(this, "clockTimer", null);
      __publicField(this, "commandBusy", false);
      __publicField(this, "connectionBanner", null);
      __publicField(this, "connectionTroubled", false);
      __publicField(this, "currentScreenKey", "");
      __publicField(this, "lastTimerAnnouncement", null);
      __publicField(this, "lastWarningSecond", null);
      __publicField(this, "liveRegion", null);
      __publicField(this, "readiness", null);
      __publicField(this, "setup", {});
      __publicField(this, "sound", new SoundEngine());
      __publicField(this, "stage", null);
      __publicField(this, "toolbar", null);
      __publicField(this, "toolbarNav", null);
      __publicField(this, "stageMode", null);
      __publicField(this, "abortDialog", null);
      __publicField(this, "state", null);
      __publicField(this, "tts");
      __publicField(this, "unlockSound", () => {
        void this.sound.unlock().then(() => {
          var _a;
          if (((_a = this.state) == null ? void 0 : _a.phase) === "lobby") {
            this.sound.startLobby();
          }
        });
      });
      __publicField(this, "handleOnline", () => {
        if (this.state && ACTIVE_PHASES.has(this.state.phase)) {
          this.poller.kick();
        } else if (!this.state) {
          void this.bootstrap();
        }
      });
      this.api = new LiveApi(config);
      this.tts = new TtsPlayer(config);
      this.reconnectKey = `mod_quizgeist:live-host:${config.cmid}`;
      this.poller = new AdaptivePoller(
        (signal) => this.poll(signal),
        (error, failures) => this.handlePollError(error, failures)
      );
    }
    async init() {
      this.root.classList.add("quizgeist-host-root");
      this.root.dataset.quizgeistRoot = "host";
      this.root.dataset.quizgeistTheme = this.config.theme || "hell";
      this.root.dataset.quizgeistSeason = this.config.season || "herbst";
      this.root.replaceChildren();
      this.connectionBanner = liveElement("div", "quizgeist-host-connection", {
        "aria-live": "polite",
        hidden: true,
        role: "status",
        "data-live-connection": true
      });
      this.liveRegion = liveElement("div", "quizgeist-live-visually-hidden", {
        "aria-atomic": "true",
        "aria-live": "polite",
        role: "status"
      });
      this.stage = liveElement("section", "quizgeist-host-stage", {
        "aria-busy": "true",
        "data-live-screen": "loading"
      });
      this.toolbar = liveElement("div", "quizgeist-host-toolbar", { hidden: true });
      this.toolbarNav = liveElement("div", "quizgeist-host-toolbar__nav");
      this.stageMode = new StageModeController(this.root, {
        announce: (message) => this.announce(message),
        text: (key, fallback) => this.text(key, fallback)
      });
      this.toolbar.append(this.toolbarNav, this.stageMode.createToggle());
      this.root.append(this.connectionBanner, this.liveRegion, this.stage, this.toolbar);
      this.stageMode.attach();
      this.root.addEventListener("pointerdown", this.unlockSound, { once: true });
      this.renderLoading();
      this.clockTimer = window.setInterval(() => this.tickClock(), 250);
      window.addEventListener("online", this.handleOnline);
      await this.bootstrap();
    }
    destroy() {
      var _a, _b, _c;
      (_a = this.stageMode) == null ? void 0 : _a.detach();
      void ((_b = this.stageMode) == null ? void 0 : _b.leave());
      if (this.abortDialog) {
        this.abortDialog.remove();
        this.abortDialog = null;
      }
      (_c = this.bootstrapController) == null ? void 0 : _c.abort();
      this.bootstrapController = null;
      this.poller.stop();
      if (this.clockTimer !== null) {
        window.clearInterval(this.clockTimer);
        this.clockTimer = null;
      }
      window.removeEventListener("online", this.handleOnline);
      this.destroyCardScan();
      this.sound.destroy();
      this.tts.stop();
    }
    text(key, fallback, values = {}) {
      return liveString(this.config.strings, key, values, fallback);
    }
    setTabsVisible(visible) {
      var _a;
      const tabsElementId = this.config.tabsElementId;
      if (tabsElementId) {
        const tabs = this.root.ownerDocument.getElementById(tabsElementId);
        if (tabs) {
          tabs.hidden = !visible;
        }
      }
      if (this.toolbar) {
        this.toolbar.hidden = visible;
        if (visible) {
          (_a = this.toolbarNav) == null ? void 0 : _a.replaceChildren();
        }
      }
    }
    announce(message) {
      if (!this.liveRegion) {
        return;
      }
      this.liveRegion.textContent = "";
      window.requestAnimationFrame(() => {
        if (this.liveRegion) {
          this.liveRegion.textContent = message;
        }
      });
    }
    renderLoading() {
      this.setTabsVisible(true);
      if (!this.stage) {
        return;
      }
      this.stage.dataset.liveScreen = "loading";
      this.stage.setAttribute("aria-busy", "true");
      const loading = liveElement("div", "quizgeist-host-state-card", {
        role: "status"
      });
      const spinner = liveElement("span", "quizgeist-host-spinner", {
        "aria-hidden": "true"
      });
      appendChildren(
        loading,
        spinner,
        liveElement("p", "quizgeist-host-state-card__text", {
          text: this.text(
            "live:connection:loading",
            "Live-Session wird geladen \u2026"
          )
        })
      );
      this.stage.replaceChildren(loading);
    }
    async bootstrap(useStoredSession = true) {
      var _a;
      (_a = this.bootstrapController) == null ? void 0 : _a.abort();
      this.bootstrapController = new AbortController();
      this.renderLoading();
      const sessionId = useStoredSession ? this.storedSessionId() : null;
      try {
        const result = await this.api.post(
          "live_host_bootstrap",
          sessionId ? { sessionId } : {},
          this.bootstrapController.signal
        );
        this.setup = result.setup || {};
        this.readiness = result.readiness || null;
        if (result.state) {
          this.applyState(result.state, true);
        } else {
          this.clearStoredSession();
          this.state = null;
          this.poller.stop();
          this.renderSetup();
        }
      } catch (error) {
        if (isAbortError(error)) {
          return;
        }
        if (error instanceof LiveApiError && error.status === 404 && sessionId) {
          this.clearStoredSession();
          await this.bootstrap(false);
          return;
        }
        if (this.isAuthenticationError(error)) {
          this.renderFatalConnection(error);
        } else {
          this.renderBootstrapError(error);
        }
      }
    }
    async poll(signal) {
      var _a;
      const state = this.state;
      if (!state || !ACTIVE_PHASES.has(state.phase)) {
        this.poller.stop();
        return {};
      }
      const result = await this.api.post(
        "live_host_poll",
        {
          knownAggregateRevision: state.aggregateRevision || 0,
          knownStateVersion: state.stateVersion,
          sessionId: state.sessionId
        },
        signal
      );
      this.updateClock(result.serverTimeMs);
      if (result.changed && result.state) {
        this.applyState(result.state);
      } else if (this.state && this.state.sessionId === state.sessionId && Number(result.stateVersion) >= this.state.stateVersion) {
        this.state = {
          ...this.state,
          aggregate: result.aggregate === void 0 ? this.state.aggregate : result.aggregate,
          aggregateRevision: Number.isFinite(result.aggregateRevision) ? Number(result.aggregateRevision) : this.state.aggregateRevision,
          answerCount: Number.isFinite(result.answerCount) ? Math.max(0, Number(result.answerCount)) : this.state.answerCount,
          distribution: Array.isArray(result.distribution) ? result.distribution : this.state.distribution,
          // F5: the traffic light travels with the aggregate, so an unchanged
          // state version must not resurrect a stale verdict.
          hingeStatus: result.hingeStatus === void 0 ? (_a = this.state.hingeStatus) != null ? _a : null : normaliseHingeStatus(result.hingeStatus),
          serverTimeMs: result.serverTimeMs,
          stateVersion: Math.max(this.state.stateVersion, result.stateVersion)
        };
        this.updateRootStateData();
        this.patchCurrentScreen();
      }
      this.markConnectionRestored();
      return { pollAfterMs: result.pollAfterMs };
    }
    handlePollError(error, failures) {
      var _a;
      if (isAbortError(error)) {
        return { stop: true };
      }
      if (error instanceof LiveApiError && error.status === 409) {
        if ((_a = error.data) == null ? void 0 : _a.state) {
          this.applyState(error.data.state);
        }
        this.showConnectionMessage(
          this.text(
            "live:error:conflict",
            "Die Session wurde an anderer Stelle weitergeschaltet."
          ),
          "warning"
        );
        return { delayMs: 1100 };
      }
      if (this.isAuthenticationError(error)) {
        this.renderFatalConnection(error);
        return { stop: true };
      }
      this.connectionTroubled = true;
      this.showConnectionMessage(
        failures > 1 ? this.text(
          "live:connection:offline",
          "Die Verbindung ist gerade unterbrochen."
        ) : this.text(
          "live:connection:reconnecting",
          "Kurz gehakt. Wir versuchen es nochmal \u2026"
        ),
        "warning"
      );
      return {};
    }
    applyState(incoming, initial = false) {
      var _a;
      if (this.state && Number(incoming.sessionId) === this.state.sessionId && Number(incoming.stateVersion) < this.state.stateVersion) {
        return;
      }
      const previousPhase = (_a = this.state) == null ? void 0 : _a.phase;
      const previousState = this.state;
      const state = this.normaliseState(incoming);
      this.state = state;
      if (state.phase === "ended" || state.phase === "aborted") {
        this.clearStoredSession();
      } else {
        this.storeSession(state.sessionId);
      }
      this.updateClock(state.serverTimeMs);
      this.root.dataset.quizgeistTheme = this.config.theme || "hell";
      this.root.dataset.quizgeistSeason = this.config.season || "herbst";
      this.updateRootStateData();
      const nextScreenKey = this.screenKey(state);
      if (initial || nextScreenKey !== this.currentScreenKey) {
        this.currentScreenKey = nextScreenKey;
        this.renderState();
      } else {
        this.patchCurrentScreen();
      }
      if (ACTIVE_PHASES.has(state.phase)) {
        if (!this.poller.isRunning()) {
          this.poller.start(false);
        }
      } else {
        this.poller.stop();
      }
      if (previousPhase !== state.phase) {
        this.announce(this.phaseLabel(state.phase));
      }
      this.handleSoundTransition(previousState, state);
      this.markConnectionRestored();
    }
    handleSoundTransition(previous, state) {
      var _a, _b;
      if (state.phase === "lobby") {
        this.sound.startLobby();
      } else {
        this.sound.stopLobby();
      }
      if (!previous || previous.phase === state.phase) {
        return;
      }
      this.tts.stop();
      this.lastWarningSecond = null;
      if (state.phase === "question" && state.phaseStartedAtMs <= this.serverNow()) {
        this.sound.play("go");
      } else if (state.phase === "reveal") {
        this.sound.play(
          ((_b = (_a = state.question) == null ? void 0 : _a.policyDescriptor) == null ? void 0 : _b.showsCorrectness) === false ? "tap" : "correct"
        );
      } else if (state.phase === "podium") {
        this.sound.play("podium");
      }
    }
    normaliseState(state) {
      var _a;
      return {
        ...state,
        answerCount: Math.max(0, Number(state.answerCount || 0)),
        aggregate: state.aggregate && typeof state.aggregate === "object" ? state.aggregate : null,
        aggregateRevision: Math.max(0, Number(state.aggregateRevision || 0)),
        currentIndex: Math.max(-1, Number((_a = state.currentIndex) != null ? _a : -1)),
        distribution: Array.isArray(state.distribution) ? state.distribution : [],
        hingeStatus: normaliseHingeStatus(state.hingeStatus),
        interactionStage: state.interactionStage === null || state.interactionStage === void 0 ? null : String(state.interactionStage),
        joinCode: String(state.joinCode || ""),
        phaseEndsAtMs: Number(state.phaseEndsAtMs || 0),
        phaseStartedAtMs: Number(state.phaseStartedAtMs || 0),
        playerCount: Math.max(0, Number(state.playerCount || 0)),
        players: Array.isArray(state.players) ? state.players : [],
        podium: Array.isArray(state.podium) ? state.podium : [],
        question: state.question ? {
          ...state.question,
          choices: Array.isArray(state.question.choices) ? state.question.choices : [],
          typeData: state.question.typeData && typeof state.question.typeData === "object" ? state.question.typeData : {}
        } : null,
        ranking: Array.isArray(state.ranking) ? state.ranking : [],
        serverTimeMs: Number(state.serverTimeMs || Date.now()),
        sessionId: Number(state.sessionId || 0),
        stateVersion: Math.max(0, Number(state.stateVersion || 0)),
        teamPodium: Array.isArray(state.teamPodium) ? state.teamPodium : [],
        teamRanking: Array.isArray(state.teamRanking) ? state.teamRanking : [],
        totalQuestions: Math.max(0, Number(state.totalQuestions || 0))
      };
    }
    updateRootStateData() {
      if (!this.state) {
        delete this.root.dataset.sessionId;
        delete this.root.dataset.stateVersion;
        delete this.root.dataset.livePhase;
        delete this.root.dataset.questionId;
        delete this.root.dataset.questionRootId;
        delete this.root.dataset.questionVersion;
        return;
      }
      this.root.dataset.sessionId = String(this.state.sessionId);
      this.root.dataset.stateVersion = String(this.state.stateVersion);
      this.root.dataset.livePhase = this.state.phase;
      if (this.state.question) {
        this.root.dataset.questionId = String(this.state.question.id);
        this.root.dataset.questionRootId = String(this.state.question.rootId);
        this.root.dataset.questionVersion = String(this.state.question.version);
      } else {
        delete this.root.dataset.questionId;
        delete this.root.dataset.questionRootId;
        delete this.root.dataset.questionVersion;
      }
    }
    updateClock(serverTimeMs) {
      if (Number.isFinite(serverTimeMs) && serverTimeMs > 0) {
        this.clockOffsetMs = serverTimeMs - Date.now();
      }
    }
    serverNow() {
      return Date.now() + this.clockOffsetMs;
    }
    screenKey(state) {
      var _a, _b, _c;
      const screen = this.derivedScreen(state);
      const question = state.question;
      const interactionStage = question ? String(
        state.interactionStage || ((_a = question.policyDescriptor) == null ? void 0 : _a.currentStage) || question.interactionStage || ((_b = question.typeData) == null ? void 0 : _b.interactionStage) || ((_c = question.typeData) == null ? void 0 : _c.stage) || ""
      ) : "";
      return `${screen}:${(question == null ? void 0 : question.id) || 0}:${(question == null ? void 0 : question.questionToken) || ""}:${interactionStage}`;
    }
    derivedScreen(state) {
      if (state.phase === "question" && state.phaseStartedAtMs > this.serverNow()) {
        return "countdown";
      }
      return state.phase;
    }
    renderState() {
      const state = this.state;
      if (!state || !this.stage) {
        this.renderSetup();
        return;
      }
      this.setTabsVisible(false);
      this.stage.setAttribute("aria-busy", "false");
      this.lastTimerAnnouncement = null;
      const screen = this.derivedScreen(state);
      this.renderToolbarNavigation(screen);
      if (screen !== "question") {
        this.destroyCardScan();
      }
      switch (screen) {
        case "lobby":
          this.renderLobby(state);
          break;
        case "countdown":
          this.renderCountdown(state);
          break;
        case "question":
          this.renderQuestion(state);
          break;
        case "reveal":
          this.renderReveal(state);
          break;
        case "scoreboard":
          this.renderScoreboard(state);
          break;
        case "podium":
          this.renderPodium(state);
          break;
        case "aborted":
        case "ended":
          this.renderEnded(state);
          break;
        default:
          this.renderBootstrapError(
            new Error(this.text("live:error:request", "Unbekannter Sessionzustand."))
          );
      }
    }
    renderToolbarNavigation(screen) {
      var _a;
      const navigation = this.toolbarNav;
      navigation == null ? void 0 : navigation.replaceChildren();
      if (!navigation || screen !== "ended") {
        return;
      }
      const capabilities = this.config.capabilities;
      if ((capabilities == null ? void 0 : capabilities.viewReports) === true && this.config.reportsUrl) {
        navigation.append(liveElement("a", "quizgeist-host-button quizgeist-host-button--secondary", {
          href: this.config.reportsUrl,
          text: ((_a = this.config.locale) == null ? void 0 : _a.toLowerCase().startsWith("en")) ? "View report" : "Bericht ansehen"
        }));
      }
      if ((capabilities == null ? void 0 : capabilities.manage) === true && this.config.editorUrl) {
        navigation.append(liveElement("a", "quizgeist-host-button quizgeist-host-button--secondary", {
          href: this.config.editorUrl,
          text: this.text("host:action:toeditor", "Zum Editor")
        }));
      }
    }
    renderSetup() {
      var _a, _b, _c;
      this.setTabsVisible(true);
      void ((_a = this.stageMode) == null ? void 0 : _a.leave());
      if (!this.stage) {
        return;
      }
      this.state = null;
      this.currentScreenKey = "setup";
      this.clearStoredSession();
      this.updateRootStateData();
      this.root.dataset.livePhase = "setup";
      this.stage.dataset.liveScreen = "setup";
      this.stage.setAttribute("aria-busy", "false");
      const shell = liveElement("div", "quizgeist-host-setup");
      const brand = this.createBrand();
      const card = liveElement("div", "quizgeist-host-setup__card");
      const eyebrow = liveElement("p", "quizgeist-host-eyebrow", {
        text: "Quizgeist Live"
      });
      const title = liveElement("h1", "quizgeist-host-setup__title", {
        text: this.text("host:setup:title", "Eine Live-Session starten"),
        tabindex: -1
      });
      const description = liveElement("p", "quizgeist-host-setup__description", {
        text: this.text(
          "host:setup:description",
          "Erstellen Sie eine Lobby und laden Sie Ihre Lerngruppe ein."
        )
      });
      const form = liveElement("form", "quizgeist-host-setup__form", {
        "data-live-host-create": true
      });
      const selectId = `quizgeist-host-name-mode-${this.config.cmid}`;
      const label = liveElement("label", "quizgeist-host-field__label", {
        for: selectId,
        text: this.text("host:namemode:label", "Teilnehmernamen")
      });
      const select = liveElement("select", "quizgeist-host-select", {
        id: selectId,
        name: "nameMode"
      });
      select.append(
        liveElement("option", "", {
          text: this.text("host:namemode:real", "Echte Namen"),
          value: "real"
        }),
        liveElement("option", "", {
          text: this.text("host:namemode:custom", "Eigene Spitznamen"),
          value: "custom"
        }),
        liveElement("option", "", {
          text: this.text("host:namemode:generated", "Lustige Namen erzeugen"),
          value: "generated"
        })
      );
      const modeId = `quizgeist-host-mode-${this.config.cmid}`;
      const modeLabel = liveElement("label", "quizgeist-host-field__label", {
        for: modeId,
        text: this.text("defaultmode", "Spielmodus")
      });
      const mode = liveElement("select", "quizgeist-host-select", {
        "data-live-mode": true,
        id: modeId,
        name: "mode"
      });
      const allowedModes = ((_b = this.setup.allowedModes) == null ? void 0 : _b.length) ? this.setup.allowedModes : ["classic", "accuracy", "team", "security"];
      allowedModes.forEach((key) => {
        mode.append(liveElement("option", "", {
          text: this.modeLabel(key),
          value: key
        }));
      });
      mode.value = this.setup.defaultMode || "classic";
      const teamFields = liveElement("fieldset", "quizgeist-host-team-setup", {
        hidden: mode.value !== "team"
      });
      teamFields.append(liveElement("legend", "quizgeist-host-field__label", {
        text: this.text("host:team:title", "Teams")
      }));
      const source = liveElement("select", "quizgeist-host-select", {
        "data-live-team-source": true,
        name: "teamSource"
      });
      const groupReadiness = this.setup.groupReadiness;
      if ((((_c = this.setup.moodleGroups) == null ? void 0 : _c.length) || 0) > 0) {
        source.append(liveElement("option", "", {
          disabled: (groupReadiness == null ? void 0 : groupReadiness.tooManyGroups) === true,
          text: this.text("host:team:source:groups", "Moodle-Gruppen"),
          value: "groups"
        }));
      }
      source.append(liveElement("option", "", {
        text: this.text("host:team:source:free", "Freie Teams"),
        value: "free"
      }));
      if ((groupReadiness == null ? void 0 : groupReadiness.tooManyGroups) === true) {
        source.value = "free";
      }
      const tooManyGroups = liveElement("p", "quizgeist-host-setup__description", {
        hidden: (groupReadiness == null ? void 0 : groupReadiness.tooManyGroups) !== true,
        role: "alert",
        text: this.text(
          "host:team:toomanygroups",
          `${(groupReadiness == null ? void 0 : groupReadiness.groupCount) || 0} Moodle-Gruppen \xFCberschreiten das Limit von ${(groupReadiness == null ? void 0 : groupReadiness.maxGroupCount) || 12}; verwenden Sie freie Teams.`,
          {
            groupCount: (groupReadiness == null ? void 0 : groupReadiness.groupCount) || 0,
            maxGroupCount: (groupReadiness == null ? void 0 : groupReadiness.maxGroupCount) || 12
          }
        )
      });
      const unassignedCount = (groupReadiness == null ? void 0 : groupReadiness.unassignedEnrolledCount) || 0;
      const unassignedNotice = liveElement("p", "quizgeist-host-setup__description", {
        hidden: source.value !== "groups" || unassignedCount <= 0,
        text: this.text(
          "host:team:unassignednotice",
          `${unassignedCount} Personen ohne Moodle-Gruppe werden dem Auffangteam \u201EOhne Moodle-Gruppe\u201C zugeteilt.`,
          { a: unassignedCount }
        )
      });
      const freeTeams = liveElement("div", "quizgeist-host-free-teams", {
        hidden: source.value !== "free"
      });
      const teamNames = liveElement("div", "quizgeist-host-team-names");
      const addTeam = liveButton(
        this.text("host:team:add", "Team hinzuf\xFCgen"),
        "quizgeist-host-button quizgeist-host-button--secondary",
        { "data-live-team-add": true }
      );
      const updateTeamControls = () => {
        const rows = Array.from(
          teamNames.querySelectorAll("[data-live-team-row]")
        );
        rows.forEach((row, index) => {
          const input = row.querySelector("[data-live-team-name]");
          const remove = row.querySelector("[data-live-team-remove]");
          if (input) {
            input.setAttribute(
              "aria-label",
              this.text("host:team:label", "Team {$a}", { a: index + 1 })
            );
            input.placeholder = this.text(
              "host:team:label",
              "Team {$a}",
              { a: index + 1 }
            );
          }
          if (remove) {
            remove.disabled = rows.length <= 2;
          }
        });
        addTeam.disabled = rows.length >= 12;
      };
      const appendTeam = (teamName = "") => {
        if (teamNames.childElementCount >= 12) {
          return;
        }
        const row = liveElement("div", "quizgeist-host-team-row", {
          "data-live-team-row": true
        });
        const teamInput = liveElement("input", "quizgeist-host-text-input", {
          "data-live-team-name": true,
          maxlength: 40,
          name: "teamName",
          type: "text",
          value: teamName
        });
        const remove = liveButton(
          "\xD7",
          "quizgeist-host-button quizgeist-host-button--secondary quizgeist-host-team-remove",
          {
            "aria-label": this.text("host:team:remove", "Team entfernen"),
            "data-live-team-remove": true
          }
        );
        remove.addEventListener("click", () => {
          row.remove();
          updateTeamControls();
        });
        row.append(teamInput, remove);
        teamNames.append(row);
        updateTeamControls();
      };
      ["Moos", "Funken", "Wellen", "Sterne"].forEach((teamName) => {
        appendTeam(teamName);
      });
      addTeam.addEventListener("click", () => {
        var _a2;
        appendTeam();
        (_a2 = teamNames.querySelector(
          "[data-live-team-row]:last-child [data-live-team-name]"
        )) == null ? void 0 : _a2.focus();
      });
      freeTeams.append(teamNames, addTeam);
      const updateTeamSource = () => {
        freeTeams.hidden = source.value !== "free";
        unassignedNotice.hidden = source.value !== "groups" || unassignedCount <= 0;
      };
      source.addEventListener("change", updateTeamSource);
      updateTeamSource();
      teamFields.append(source, tooManyGroups, unassignedNotice, freeTeams);
      const securityFields = liveElement("div", "quizgeist-host-security-setup", {
        hidden: mode.value !== "security"
      });
      const blockedLabel = liveElement("label", "quizgeist-host-field__label", {
        text: "Gesperrte Namen (eine Zeile je Begriff)"
      });
      const blockedNames = liveElement("textarea", "quizgeist-host-text-input", {
        "data-live-blocked-names": true,
        name: "blockedNames",
        rows: 3
      });
      blockedLabel.append(blockedNames);
      securityFields.append(
        liveElement("p", "quizgeist-host-setup__description", {
          text: "Nur eingeschriebene Nutzer k\xF6nnen beitreten; der Namensfilter ist aktiv."
        }),
        blockedLabel
      );
      mode.addEventListener("change", () => {
        teamFields.hidden = mode.value !== "team";
        securityFields.hidden = mode.value !== "security";
      });
      const submit = liveElement("button", "quizgeist-host-button quizgeist-host-button--primary", {
        type: "submit",
        text: this.text("host:action:create", "Lobby erstellen"),
        "data-action": "create-session"
      });
      form.append(
        label,
        select,
        modeLabel,
        mode,
        teamFields,
        securityFields,
        this.createStressFreeFields(),
        this.renderReadiness(),
        submit
      );
      form.addEventListener("submit", (event) => {
        event.preventDefault();
        void this.createSession(form, submit);
      });
      card.append(eyebrow, title, description, form);
      shell.append(brand, card);
      this.stage.replaceChildren(shell);
      title.focus();
    }
    /**
     * F1 Stressarm-Standard: the free block of the base package.
     *
     * Deliberately no feature gate — these four settings are the strongest
     * argument of the base package and must not hang on any addon. The works
     * defaults come from the activity; the teacher may override them per
     * session.
     */
    createStressFreeFields() {
      const block = liveElement("fieldset", "quizgeist-host-setup__stressfree", {
        "data-live-stressfree": true
      });
      block.append(liveElement("legend", "quizgeist-host-field__legend", {
        text: this.text("host:setup:stressfree", "Stressarm")
      }));
      const defaults = this.setup.stressFree || {};
      const paceId = `quizgeist-host-pace-${this.config.cmid}`;
      block.append(liveElement("label", "quizgeist-host-field__label", {
        for: paceId,
        text: this.text("host:setup:pace", "Punktvergabe")
      }));
      const pace = liveElement("select", "quizgeist-host-select", {
        "data-live-pace": true,
        id: paceId,
        name: "pace"
      });
      ["even", "timed"].forEach((value2) => {
        const option = liveElement("option", "", {
          text: this.text(`pacemode:${value2}`, value2),
          value: value2
        });
        option.selected = (defaults.pace || "even") === value2;
        pace.append(option);
      });
      block.append(pace);
      const boardId = `quizgeist-host-leaderboard-${this.config.cmid}`;
      block.append(liveElement("label", "quizgeist-host-field__label", {
        for: boardId,
        text: this.text("host:setup:leaderboard", "Rangliste")
      }));
      const board = liveElement("select", "quizgeist-host-select", {
        "data-live-leaderboard": true,
        id: boardId,
        name: "leaderboard"
      });
      ["own", "team", "full"].forEach((value2) => {
        const option = liveElement("option", "", {
          text: this.text(`leaderboard:${value2}`, value2),
          value: value2
        });
        option.selected = (defaults.leaderboard || "own") === value2;
        board.append(option);
      });
      block.append(board);
      [
        ["timer", "host:setup:timer", "Countdown anzeigen"],
        ["sound", "host:setup:sound", "T\xF6ne erlauben"]
      ].forEach(([name, key, fallback]) => {
        const id = `quizgeist-host-${name}-${this.config.cmid}`;
        const wrapper = liveElement("div", "quizgeist-host-field__switch");
        const input = liveElement("input", "quizgeist-host-checkbox", {
          id,
          name,
          type: "checkbox",
          value: "1"
        });
        input.checked = name === "timer" ? defaults.timerVisible !== false : defaults.soundEnabled !== false;
        wrapper.append(
          input,
          liveElement("label", "quizgeist-host-field__label", {
            for: id,
            text: this.text(key, fallback)
          })
        );
        block.append(wrapper);
      });
      return block;
    }
    renderReadiness() {
      var _a;
      const readiness = liveElement("div", "quizgeist-host-setup__readiness", {
        role: "status"
      });
      const text2 = liveElement("p", "quizgeist-host-setup__readiness-text");
      const data2 = this.readiness;
      const questionCount = Math.max(0, Number((data2 == null ? void 0 : data2.questionCount) || 0));
      const playableQuestionCount = Math.max(
        0,
        Number((data2 == null ? void 0 : data2.playableQuestionCount) || 0)
      );
      if ((data2 == null ? void 0 : data2.ready) === true) {
        text2.textContent = this.text(
          "host:readiness:ready",
          "{$a} Fragen sind spielbereit.",
          { a: questionCount, count: questionCount }
        );
      } else if (playableQuestionCount <= 0) {
        text2.textContent = this.text(
          "host:readiness:none",
          "Keine spielbereiten Fragen vorhanden."
        );
        this.appendEditorLink(readiness);
      } else if (playableQuestionCount < questionCount) {
        text2.textContent = this.text(
          "host:readiness:partial",
          "{$a->playable} von {$a->total} Fragen sind spielbereit.",
          {
            a: playableQuestionCount,
            playable: playableQuestionCount,
            total: questionCount
          }
        );
        appendChildren(
          text2,
          " ",
          this.text(
            "host:readiness:partialhint",
            ((_a = this.config.locale) == null ? void 0 : _a.toLowerCase().startsWith("en")) ? "A live round starts only when all questions are ready." : "Eine Live-Runde startet erst, wenn alle Fragen fertig sind."
          )
        );
        this.appendEditorLink(readiness);
      }
      readiness.prepend(text2);
      return readiness;
    }
    appendEditorLink(readiness) {
      if (!this.config.editorUrl) {
        return;
      }
      const actions = liveElement("div", "quizgeist-host-setup__readiness-actions");
      actions.append(liveElement("a", "quizgeist-host-button quizgeist-host-button--secondary", {
        href: this.config.editorUrl,
        text: this.text("host:action:toeditor", "Zum Editor")
      }));
      readiness.append(actions);
    }
    async createSession(form, submit) {
      var _a, _b;
      const formData = new FormData(form);
      const rawNameMode = String(formData.get("nameMode") || "");
      const nameMode = rawNameMode === "custom" || rawNameMode === "generated" ? rawNameMode : "real";
      const rawMode = String(formData.get("mode") || "classic");
      const mode = ["accuracy", "classic", "security", "team"].includes(rawMode) ? rawMode : "classic";
      const teamNames = Array.from(
        form.querySelectorAll("[data-live-team-name]")
      ).map((input) => input.value.trim()).filter(Boolean);
      const blockedNames = String(formData.get("blockedNames") || "").split(/\r?\n|,/).map((name) => name.trim()).filter(Boolean);
      submit.disabled = true;
      submit.setAttribute("aria-busy", "true");
      const originalLabel = submit.textContent || "";
      submit.textContent = this.text(
        "live:connection:loading",
        "Live-Session wird geladen \u2026"
      );
      try {
        const result = await this.api.post(
          "live_session_create",
          {
            blockedNames,
            // F1: the stress-free axis travels with the session; the server
            // validates and stores it, the client only proposes.
            leaderboard: String(formData.get("leaderboard") || "own"),
            mode,
            nameMode,
            pace: String(formData.get("pace") || "even"),
            sound: formData.get("sound") === "1",
            teamNames,
            teamSource: String(formData.get("teamSource") || "free"),
            timer: formData.get("timer") === "1"
          }
        );
        if (!result.state) {
          throw new LiveApiError(
            this.text("live:error:request", "Die Lobby konnte nicht erstellt werden."),
            "invalid_response"
          );
        }
        this.applyState(result.state, true);
      } catch (error) {
        if (this.isAuthenticationError(error)) {
          this.renderFatalConnection(error);
          return;
        }
        this.setTabsVisible(true);
        void ((_a = this.stageMode) == null ? void 0 : _a.leave());
        const message = error instanceof LiveApiError && (error.code === "no_playable_questions" || error.code === "noplayablequestions") ? this.text(
          "live:error:noplayablequestions",
          "F\xFCr eine Live-Session werden fertige Fragen ben\xF6tigt."
        ) : this.errorMessage(error);
        this.showConnectionMessage(message, "error");
        (_b = this.connectionBanner) == null ? void 0 : _b.scrollIntoView({ block: "nearest" });
        this.announce(message);
        submit.disabled = false;
        submit.removeAttribute("aria-busy");
        submit.textContent = originalLabel;
      }
    }
    renderLobby(state) {
      if (!this.stage) {
        return;
      }
      this.stage.dataset.liveScreen = "lobby";
      const shell = liveElement("div", "quizgeist-host-screen quizgeist-host-lobby");
      const topbar = this.createTopbar(state);
      const main = liveElement("div", "quizgeist-host-lobby__join");
      const pinCard = liveElement("section", "quizgeist-host-pin-card");
      const pinLabel = liveElement("h1", "quizgeist-host-pin-card__label", {
        tabindex: -1,
        text: this.text("host:lobby:pin", "Spielcode")
      });
      const pin = liveElement("p", "quizgeist-host-pin-card__code", {
        text: formatJoinCode(state.joinCode),
        "data-join-code": state.joinCode,
        "data-live-join-code": true
      });
      const hint = liveElement("p", "quizgeist-host-pin-card__hint", {
        text: this.text(
          "host:lobby:joinhint",
          "Code am Handy eingeben und los geht\u2019s."
        )
      });
      pinCard.append(pinLabel, pin, hint);
      const qrCard = liveElement("section", "quizgeist-host-qr");
      const qrHost = liveElement("div", "quizgeist-host-qr__image");
      const qrLabel = liveElement("p", "quizgeist-host-qr__caption", {
        text: this.text("host:lobby:qr", "QR-Code zum Beitreten")
      });
      const joinUrl = this.joinUrl(state.joinCode);
      const joinLink = liveElement("a", "quizgeist-host-qr__link", {
        href: joinUrl,
        text: this.text("host:lobby:joinhint", "Mit Spielcode beitreten")
      });
      qrCard.append(qrHost, qrLabel, joinLink);
      void renderLocalQrCode(
        qrHost,
        joinUrl,
        `${this.text("host:lobby:qr", "QR-Code zum Beitreten")}: ${formatJoinCode(state.joinCode)}`
      ).catch(() => {
        if (qrHost.isConnected) {
          qrHost.replaceChildren(
            liveElement("p", "quizgeist-host-qr__fallback", {
              text: formatJoinCode(state.joinCode)
            })
          );
        }
      });
      main.append(pinCard, qrCard);
      const players = liveElement("section", "quizgeist-host-lobby__players");
      const playersHeader = liveElement("div", "quizgeist-host-section-heading");
      const playersTitle = liveElement("h2", "quizgeist-host-section-heading__title", {
        text: this.text("host:lobby:players", "Mitspieler")
      });
      const playerCount = liveElement("p", "quizgeist-host-section-heading__count", {
        text: this.playerCountLabel(state.playerCount),
        "data-live-player-count": true
      });
      playersHeader.append(playersTitle, playerCount);
      const playerGrid = liveElement("div", "quizgeist-host-player-grid", {
        "aria-live": "polite",
        "data-live-player-grid": true,
        tabindex: 0
      });
      this.reconcilePlayers(playerGrid, state.players);
      players.append(playersHeader, playerGrid);
      const footer = liveElement("footer", "quizgeist-host-controlbar");
      const modes = liveElement("div", "quizgeist-host-mode-chips");
      appendChildren(
        modes,
        liveElement("span", "quizgeist-host-mode-chip is-active", {
          text: this.modeLabel(state.mode)
        }),
        liveElement("span", "quizgeist-host-mode-chip", {
          text: this.nameModeLabel(state.nameMode)
        })
      );
      const actions = liveElement("div", "quizgeist-host-controlbar__actions");
      actions.append(
        this.commandButton(
          "abort",
          this.text("host:action:abort", "Session abbrechen"),
          "quiet-danger"
        ),
        this.commandButton(
          "start",
          this.text("host:action:start", "Spiel starten"),
          "primary",
          state.playerCount <= 0
        )
      );
      footer.append(modes, actions);
      shell.append(topbar, main, players, footer);
      this.stage.replaceChildren(shell);
      pinLabel.focus();
    }
    renderCountdown(state) {
      if (!this.stage) {
        return;
      }
      this.stage.dataset.liveScreen = "countdown";
      const shell = liveElement("div", "quizgeist-host-screen quizgeist-host-countdown");
      const topbar = this.createTopbar(state);
      const body2 = liveElement("div", "quizgeist-host-countdown__body");
      if (state.question) {
        body2.append(
          liveElement("p", "quizgeist-host-countdown__type", {
            text: this.questionTypeLabel(state.question)
          })
        );
      }
      const number = liveElement("p", "quizgeist-host-countdown__number mq-anim-countdown-tick", {
        "aria-hidden": "true",
        "data-live-countdown": true,
        text: String(this.countdownSeconds(state))
      });
      const accessible = liveElement("p", "quizgeist-live-visually-hidden", {
        "aria-live": "polite",
        "data-live-countdown-status": true
      });
      body2.append(number, accessible);
      shell.append(topbar, body2);
      this.stage.replaceChildren(shell);
    }
    renderQuestion(state) {
      if (!this.stage) {
        return;
      }
      const question = state.question;
      if (!question) {
        this.renderBootstrapError(
          new Error(this.text("live:error:request", "Die Frage konnte nicht geladen werden."))
        );
        return;
      }
      this.stage.dataset.liveScreen = "question";
      const media = ["pin", "reveal", "slide"].includes(question.qtype) ? null : this.questionMedia(question);
      const shell = liveElement(
        "div",
        `quizgeist-host-screen quizgeist-host-question${media ? " has-media" : ""}`,
        {
          "data-live-question-id": question.id
        }
      );
      const header = liveElement("header", "quizgeist-host-question__header");
      const meta = liveElement("div", "quizgeist-host-question__meta");
      meta.append(
        liveElement("span", "quizgeist-host-chip", {
          text: this.questionProgress(question, state)
        }),
        liveElement("span", "quizgeist-host-chip", {
          text: this.questionTypeLabel(question)
        })
      );
      const answered = liveElement("p", "quizgeist-host-question__answered", {
        "aria-atomic": "true",
        "aria-live": "polite",
        "data-live-answer-count": true,
        text: this.answeredLabel(state)
      });
      const tts = createTtsControl(
        this.tts,
        questionSpeechText(question),
        this.config
      );
      header.append(meta, answered, tts, this.createTimer(state));
      const questionText = liveElement(
        "h1",
        `quizgeist-host-question__text${question.questionText.length > 70 ? " is-long" : ""}${question.questionText.length > 220 ? " is-verylong" : ""}`,
        {
          tabindex: -1,
          text: question.questionText
        }
      );
      const response = renderLiveResponse(question, {
        aggregate: state.aggregate,
        answer: null,
        audience: "host",
        interactive: false,
        nowMs: this.serverNow(),
        phaseStartedAtMs: state.phaseStartedAtMs,
        text: (key, fallback, values = {}) => this.text(key, fallback, values)
      });
      const controls = liveElement("footer", "quizgeist-host-controlbar");
      const secondary = liveElement("div", "quizgeist-host-controlbar__actions");
      secondary.append(
        this.commandButton(
          "previous",
          this.text("host:action:previous", "Vorherige Frage"),
          "secondary",
          state.currentIndex <= 0
        ),
        this.commandButton(
          "skip",
          this.text("host:action:skip", "\xDCberspringen"),
          "secondary"
        )
      );
      const primary = liveElement("div", "quizgeist-host-controlbar__actions");
      const policy = question.policyDescriptor;
      const interactionStage = String(
        (policy == null ? void 0 : policy.currentStage) || state.interactionStage || question.interactionStage || ""
      );
      primary.append(
        this.commandButton(
          "abort",
          this.text("host:action:abort", "Session abbrechen"),
          "quiet-danger"
        )
      );
      if (policy == null ? void 0 : policy.canAdvance) {
        const nextStage = policy.stages.find((stage) => stage.key === policy.nextStageKey);
        const labelKey = policy.nextStageLabelKey || (nextStage == null ? void 0 : nextStage.labelKey) || "";
        const advance = liveButton(
          labelKey === "" ? "N\xE4chste Stufe" : this.text(labelKey, "N\xE4chste Stufe"),
          "quizgeist-host-button quizgeist-host-button--primary",
          {
            "aria-busy": this.commandBusy ? "true" : "false",
            "data-live-host-interaction": true,
            "data-live-interaction-advance": interactionStage,
            disabled: this.commandBusy
          }
        );
        advance.addEventListener("click", () => {
          void this.advanceInteraction(question, interactionStage);
        });
        primary.append(advance);
      } else {
        primary.append(this.commandButton(
          "reveal",
          this.text("host:action:reveal", "Antworten aufl\xF6sen"),
          "primary"
        ));
      }
      controls.append(secondary, primary);
      const content = liveElement("div", "quizgeist-host-question__content");
      if (media) {
        content.append(media);
      }
      content.append(response);
      const aggregate = liveElement("div", "quizgeist-live-aggregate-slot", {
        "data-live-aggregate-slot": true
      });
      if (state.aggregate) {
        aggregate.append(renderLiveAggregate(
          question,
          state.aggregate,
          {
            aggregate: state.aggregate,
            answer: null,
            audience: "host",
            disabled: this.commandBusy,
            interactive: false,
            nowMs: this.serverNow(),
            onAggregateAction: (data2) => {
              this.submitAggregateInteraction(question, data2);
            },
            text: (key, fallback, values = {}) => this.text(key, fallback, values)
          }
        ));
      }
      content.append(aggregate);
      if (question.qtype === "brainstorm" && interactionStage === "group") {
        content.append(this.renderBrainstormGrouping(state));
      }
      const cards = this.cardScanPanel(state, question);
      if (cards !== null) {
        content.append(cards);
      }
      shell.append(header, questionText, content, controls);
      this.stage.replaceChildren(shell);
      questionText.focus();
      this.patchTimer();
    }
    /**
     * F11a: build or retarget the card panel for the question on screen.
     *
     * Returns null whenever the mode does not apply — no addon, no card set, a
     * question type a card cannot answer, or no visit token to bind the scan to.
     * A locked panel is deliberately NOT offered: 2.6 says a missing addon means
     * the capability does not appear, not that it appears and refuses.
     *
     * [P11-E2]: this is the ONE place that decides whether the panel and its
     * three AI actions (`card_scan_recognise`, `card_scan_state`,
     * `card_scan_confirm`) come into being at all. The addon report is therefore
     * consulted by name here, alongside the configuration keys derived from it;
     * the panel itself never has to weigh an addon question of its own.
     */
    cardScanPanel(state, question) {
      var _a, _b;
      const sets = this.config.cardSets;
      const uploadUrl = this.config.cardScanUploadUrl;
      const printUrl = this.config.cardsPrintUrl;
      if (((_b = (_a = this.config.features) == null ? void 0 : _a.ai) == null ? void 0 : _b.installed) !== true || !Array.isArray(sets) || typeof uploadUrl !== "string" || uploadUrl === "" || typeof printUrl !== "string" || printUrl === "" || !["quiz", "truefalse"].includes(question.qtype) || question.questionToken === "") {
        this.destroyCardScan();
        return null;
      }
      const letters = question.qtype === "truefalse" ? ["A", "B"] : question.choices.map((_choice, index) => "ABCDEF"[index] || "").filter((letter) => letter !== "");
      const target = {
        letters,
        questionId: question.id,
        sessionId: state.sessionId,
        visit: question.questionToken
      };
      if (this.cardScan === null) {
        this.cardScan = new CardScanPanel(
          {
            api: this.api,
            cardSets: sets,
            cmid: this.config.cmid,
            printUrl,
            sesskey: this.config.sesskey,
            text: (key, fallback, values = {}) => this.text(key, fallback, values),
            uploadUrl
          },
          target
        );
      } else {
        this.cardScan.retarget(target);
      }
      return this.cardScan.element();
    }
    /** Release the camera and any open poll of the card panel. */
    destroyCardScan() {
      if (this.cardScan !== null) {
        this.cardScan.destroy();
        this.cardScan = null;
      }
    }
    renderReveal(state) {
      var _a, _b;
      if (!this.stage) {
        return;
      }
      const question = state.question;
      if (!question) {
        this.renderBootstrapError(
          new Error(this.text("live:error:request", "Die Frage konnte nicht geladen werden."))
        );
        return;
      }
      this.stage.dataset.liveScreen = "reveal";
      const shell = liveElement("div", "quizgeist-host-screen quizgeist-host-reveal", {
        "data-live-question-id": question.id
      });
      const topbar = this.createTopbar(state);
      const heading = liveElement("div", "quizgeist-host-reveal__heading");
      heading.append(
        liveElement("p", "quizgeist-host-eyebrow", {
          text: ((_a = question.policyDescriptor) == null ? void 0 : _a.showsCorrectness) === false ? this.text(
            "host:reveal:nocorrectness",
            "Auswertung ohne Richtig/Falsch"
          ) : this.questionProgress(question, state)
        }),
        liveElement("h1", "quizgeist-host-reveal__title", {
          text: this.text("host:reveal:title", "So wurde geantwortet"),
          tabindex: -1
        }),
        liveElement("p", "quizgeist-host-reveal__question", {
          text: question.questionText
        }),
        createTtsControl(this.tts, questionSpeechText(question), this.config)
      );
      const distribution = liveElement("div", "quizgeist-live-aggregate-slot", {
        "data-live-aggregate-slot": true
      });
      distribution.append(renderLiveAggregate(
        question,
        state.aggregate || state.distribution,
        {
          aggregate: state.aggregate,
          answer: null,
          audience: "host",
          interactive: false,
          nowMs: this.serverNow(),
          text: (key, fallback, values = {}) => this.text(key, fallback, values)
        }
      ));
      this.decorateMisconceptions(distribution, state);
      const controls = liveElement("footer", "quizgeist-host-controlbar");
      const left = liveElement("div", "quizgeist-host-controlbar__actions");
      const right = liveElement("div", "quizgeist-host-controlbar__actions");
      left.append(
        this.commandButton(
          "previous",
          this.text("host:action:previous", "Vorherige Frage"),
          "secondary",
          state.currentIndex <= 0
        )
      );
      right.append(
        this.commandButton(
          "abort",
          this.text("host:action:abort", "Session abbrechen"),
          "quiet-danger"
        ),
        this.commandButton(
          "scoreboard",
          this.text("host:action:scoreboard", "Zwischenstand zeigen"),
          "primary"
        )
      );
      controls.append(left, right);
      shell.append(
        topbar,
        heading,
        distribution,
        controls
      );
      this.stage.replaceChildren(shell);
      (_b = heading.querySelector("h1")) == null ? void 0 : _b.focus();
    }
    /**
     * Attach the F5 teacher layer to an already rendered distribution.
     *
     * Every value comes from the server payload; nothing is recomputed here.
     * A missing key means "the server did not send it" and therefore "there is
     * nothing to show" — never "unknown, let us guess".
     */
    decorateMisconceptions(container, state) {
      const rows = state.distribution.map((entry) => ({
        choiceId: String(entry.choiceId || ""),
        count: Number(entry.count || 0),
        label: typeof entry.misconceptionLabel === "string" ? entry.misconceptionLabel : null,
        percent: Number(entry.percent || 0)
      }));
      decorateDistribution(
        container,
        rows,
        normaliseHingeStatus(state.hingeStatus),
        (key, fallback, values = {}) => this.text(key, fallback, values)
      );
    }
    renderScoreboard(state) {
      var _a;
      if (!this.stage) {
        return;
      }
      this.stage.dataset.liveScreen = "scoreboard";
      const shell = liveElement("div", "quizgeist-host-screen quizgeist-host-scoreboard");
      const topbar = this.createTopbar(state);
      const heading = liveElement("div", "quizgeist-host-scoreboard__heading");
      heading.append(
        liveElement("p", "quizgeist-host-eyebrow", {
          text: this.phaseLabel("scoreboard")
        }),
        liveElement("h1", "quizgeist-host-scoreboard__title", {
          text: this.text("host:scoreboard:title", "Zwischenstand"),
          tabindex: -1
        })
      );
      const ranking = liveElement("ol", "quizgeist-host-ranking", {
        "aria-label": this.text("host:scoreboard:ranking", "Rangliste"),
        "data-live-ranking": true,
        tabindex: 0
      });
      this.renderRanking(ranking, state);
      const controls = liveElement("footer", "quizgeist-host-controlbar");
      const left = liveElement("div", "quizgeist-host-controlbar__actions");
      const right = liveElement("div", "quizgeist-host-controlbar__actions");
      left.append(
        this.commandButton(
          "previous",
          this.text("host:action:previous", "Vorherige Frage"),
          "secondary",
          state.currentIndex <= 0
        )
      );
      right.append(
        this.commandButton(
          "abort",
          this.text("host:action:abort", "Session abbrechen"),
          "quiet-danger"
        ),
        this.commandButton(
          "next",
          state.currentIndex + 1 >= state.totalQuestions ? this.text("host:podium:title", "Zum Podium") : this.text("host:action:next", "N\xE4chste Frage"),
          "primary"
        )
      );
      controls.append(left, right);
      shell.append(topbar, heading, ranking, controls);
      this.stage.replaceChildren(shell);
      (_a = heading.querySelector("h1")) == null ? void 0 : _a.focus();
    }
    renderPodium(state) {
      var _a, _b;
      if (!this.stage) {
        return;
      }
      this.stage.dataset.liveScreen = "podium";
      const shell = liveElement("div", "quizgeist-host-screen quizgeist-host-podium", {
        "data-live-podium": true
      });
      const confetti = this.createConfetti();
      const heading = liveElement("div", "quizgeist-host-podium__heading");
      heading.append(
        liveElement("h1", "quizgeist-host-podium__title", {
          text: this.text("host:podium:title", "Das Podium"),
          tabindex: -1
        }),
        liveElement("p", "quizgeist-host-podium__subtitle", {
          text: `${this.playerCountLabel(state.playerCount)} \xB7 Quizgeist`
        })
      );
      const teamMode = state.mode === "team" && (((_a = state.teamPodium) == null ? void 0 : _a.length) || 0) > 0;
      const podium = liveElement(
        "div",
        `quizgeist-host-podium__places${teamMode ? " quizgeist-host-podium__places--team" : ""}`,
        teamMode ? { "data-live-team-podium": true } : {}
      );
      const standings = teamMode ? state.teamPodium || [] : state.podium;
      const byRank = new Map(standings.map((standing) => [standing.rank, standing]));
      [2, 1, 3].forEach((rank) => {
        const standing = byRank.get(rank);
        if (standing) {
          podium.append(
            teamMode ? this.teamPodiumPlace(standing) : this.podiumPlace(standing)
          );
        } else {
          podium.append(
            liveElement(
              "div",
              `quizgeist-host-podium-place quizgeist-host-podium-place--${rank} is-empty`,
              {
                "aria-hidden": "true"
              }
            )
          );
        }
      });
      const controls = liveElement("footer", "quizgeist-host-podium__controls");
      const newRound = liveButton(
        this.text("host:action:newround", "Neue Runde"),
        "quizgeist-host-button quizgeist-host-button--secondary",
        {
          "data-action": "new-round",
          "data-live-command": true
        }
      );
      newRound.addEventListener("click", () => {
        void this.endAndPrepareNewRound();
      });
      controls.append(
        newRound,
        this.commandButton(
          "end",
          this.text("host:action:end", "Session beenden"),
          "primary"
        )
      );
      shell.append(confetti, heading, podium, controls);
      this.stage.replaceChildren(shell);
      (_b = heading.querySelector("h1")) == null ? void 0 : _b.focus();
    }
    renderEnded(state) {
      if (!this.stage) {
        return;
      }
      this.stage.dataset.liveScreen = "ended";
      const shell = liveElement("div", "quizgeist-host-screen quizgeist-host-ended");
      const brand = this.createBrand();
      const card = liveElement("div", "quizgeist-host-state-card");
      const title = liveElement("h1", "quizgeist-host-state-card__title", {
        tabindex: -1,
        text: state.phase === "aborted" ? this.text("host:action:abort", "Session abgebrochen") : this.text("host:podium:ended", "Die Runde ist beendet.")
      });
      const summary = liveElement("p", "quizgeist-host-state-card__text", {
        text: this.playerCountLabel(state.playerCount)
      });
      const newRound = liveButton(
        this.text("host:action:newround", "Neue Runde"),
        "quizgeist-host-button quizgeist-host-button--primary",
        { "data-action": "create-session" }
      );
      newRound.addEventListener("click", () => {
        this.clearStoredSession();
        this.state = null;
        this.poller.stop();
        this.renderSetup();
      });
      card.append(title, summary, newRound);
      shell.append(brand, card);
      this.stage.replaceChildren(shell);
      title.focus();
    }
    renderBootstrapError(error) {
      var _a;
      this.setTabsVisible(true);
      void ((_a = this.stageMode) == null ? void 0 : _a.leave());
      if (!this.stage) {
        return;
      }
      this.poller.stop();
      this.stage.dataset.liveScreen = "error";
      this.stage.setAttribute("aria-busy", "false");
      const card = liveElement("div", "quizgeist-host-state-card quizgeist-host-state-card--error", {
        role: "alert",
        tabindex: 0
      });
      const title = liveElement("h1", "quizgeist-host-state-card__title", {
        text: this.text("live:error:config", "Die Live-Ansicht konnte nicht gestartet werden.")
      });
      const message = liveElement("p", "quizgeist-host-state-card__text", {
        text: this.errorMessage(error)
      });
      const retry = liveButton(
        this.text("host:action:retry", "Erneut versuchen"),
        "quizgeist-host-button quizgeist-host-button--primary",
        { "data-action": "retry-bootstrap" }
      );
      retry.addEventListener("click", () => {
        void this.bootstrap();
      });
      card.append(title, message, retry);
      this.stage.replaceChildren(card);
      card.focus();
    }
    renderFatalConnection(error) {
      var _a;
      this.setTabsVisible(true);
      void ((_a = this.stageMode) == null ? void 0 : _a.leave());
      if (!this.stage) {
        return;
      }
      this.poller.stop();
      this.stage.dataset.liveScreen = "error";
      const card = liveElement("div", "quizgeist-host-state-card quizgeist-host-state-card--error", {
        role: "alert",
        tabindex: 0
      });
      const title = liveElement("h1", "quizgeist-host-state-card__title", {
        text: this.text("live:connection:expired", "Die Anmeldung ist abgelaufen.")
      });
      const message = liveElement("p", "quizgeist-host-state-card__text", {
        text: this.errorMessage(error)
      });
      const reload = liveButton(
        this.text("live:connection:reload", "Neu laden und anmelden"),
        "quizgeist-host-button quizgeist-host-button--primary",
        { "data-action": "reload" }
      );
      reload.addEventListener("click", () => window.location.reload());
      card.append(title, message, reload);
      this.stage.replaceChildren(card);
      card.focus();
    }
    createBrand() {
      const brand = liveElement("div", "quizgeist-host-brand");
      const icon = liveElement("img", "quizgeist-host-brand__icon", {
        alt: "",
        "aria-hidden": "true",
        src: this.config.brandIconUrl
      });
      const wordmark = liveElement("span", "quizgeist-host-brand__wordmark");
      wordmark.append(
        liveElement("span", "quizgeist-host-brand__quiz", { text: "Quiz" }),
        liveElement("span", "quizgeist-host-brand__geist", { text: "geist" })
      );
      brand.append(icon, wordmark);
      return brand;
    }
    createTopbar(state) {
      const topbar = liveElement("header", "quizgeist-host-topbar");
      const phase = liveElement("div", "quizgeist-host-topbar__phase");
      phase.append(
        liveElement("span", "quizgeist-host-chip", {
          text: this.phaseLabel(state.phase)
        }),
        liveElement("span", "quizgeist-host-chip", {
          text: this.modeLabel(state.mode)
        })
      );
      topbar.append(
        this.createBrand(),
        phase,
        createSoundControls(this.sound, this.config.strings || {})
      );
      return topbar;
    }
    createTimer(state) {
      const unlimited = state.phaseEndsAtMs <= 0;
      const timer = liveElement("div", `quizgeist-host-timer${unlimited ? " is-unlimited" : ""}`, {
        "aria-label": unlimited ? this.text("editor:time:none", "Ohne Zeitlimit") : "",
        role: "timer",
        "data-live-timer": true
      });
      timer.append(
        liveElement("span", "quizgeist-host-timer__value", {
          "aria-hidden": "true",
          "data-live-timer-value": true,
          text: unlimited ? "\u221E" : "0"
        }),
        liveVisuallyHidden("")
      );
      timer.dataset.phaseStartedAtMs = String(state.phaseStartedAtMs);
      timer.dataset.phaseEndsAtMs = String(state.phaseEndsAtMs);
      return timer;
    }
    questionMedia(question) {
      const media = createLiveMedia(question, {
        className: "quizgeist-host-question__media-asset",
        label: this.text("editor:field:media", "Fragenmedium")
      });
      if (!media) {
        return null;
      }
      const frame = liveElement("div", "quizgeist-host-question__media");
      frame.append(media);
      return frame;
    }
    renderRanking(container, state) {
      var _a, _b;
      container.replaceChildren();
      if (state.mode === "team" && (((_a = state.teamRanking) == null ? void 0 : _a.length) || 0) > 0) {
        container.dataset.liveTeamRanking = "";
        (_b = state.teamRanking) == null ? void 0 : _b.slice(0, 5).forEach((standing) => {
          const key = standing.teamKey || String(standing.id || "");
          const item = liveElement("li", "quizgeist-host-ranking-row quizgeist-host-ranking-row--team", {
            "data-rank": standing.rank,
            "data-team-key": key,
            "data-live-ranking-team-key": key
          });
          item.append(
            liveElement("span", "quizgeist-host-ranking-row__rank", {
              text: String(standing.rank)
            }),
            liveElement("span", "quizgeist-host-ranking-row__teammark", {
              "aria-hidden": "true",
              text: "\u2726"
            }),
            liveElement("span", "quizgeist-host-ranking-row__name", {
              text: standing.teamName || standing.name || key
            }),
            liveElement("span", "quizgeist-host-ranking-row__streak", {
              text: standing.memberCount ? `${standing.memberCount} Mitglieder` : ""
            }),
            liveElement("span", "quizgeist-host-ranking-row__delta", {
              text: standing.delta ? `${standing.delta > 0 ? "+" : ""}${standing.delta}` : ""
            }),
            liveElement("strong", "quizgeist-host-ranking-row__score", {
              text: this.pointsLabel(standing.score)
            })
          );
          container.append(item);
        });
        return;
      }
      delete container.dataset.liveTeamRanking;
      const visible = state.ranking.slice(0, 5);
      visible.forEach((standing) => {
        const item = liveElement("li", "quizgeist-host-ranking-row", {
          "data-player-id": standing.playerId,
          "data-rank": standing.rank,
          "data-live-ranking-player-id": standing.playerId
        });
        const rank = liveElement("span", "quizgeist-host-ranking-row__rank", {
          text: String(standing.rank)
        });
        const avatar = this.avatar(
          standing.avatarKey,
          standing.avatarUrl,
          standing.accessoryKey
        );
        const name = liveElement("span", "quizgeist-host-ranking-row__name", {
          text: standing.displayName
        });
        const streak = liveElement("span", "quizgeist-host-ranking-row__streak", {
          text: standing.streak ? `${this.text("live:streak", "Serie")} ${standing.streak}` : ""
        });
        const delta = liveElement("span", "quizgeist-host-ranking-row__delta", {
          text: standing.delta ? `${standing.delta > 0 ? "+" : ""}${standing.delta}` : ""
        });
        const score = liveElement("strong", "quizgeist-host-ranking-row__score", {
          text: this.pointsLabel(standing.score)
        });
        item.append(rank, avatar, name, streak, delta, score);
        container.append(item);
      });
      const remaining = Math.max(0, state.playerCount - visible.length);
      if (remaining > 0) {
        const more = liveElement("li", "quizgeist-host-ranking-row quizgeist-host-ranking-row--more", {
          text: this.text(
            "host:scoreboard:more",
            `\u2026 und ${remaining} weitere`,
            { a: remaining }
          )
        });
        container.append(more);
      }
    }
    podiumPlace(standing) {
      const place = liveElement(
        "section",
        `quizgeist-host-podium-place quizgeist-host-podium-place--${standing.rank}`,
        {
          "data-player-id": standing.playerId,
          "data-rank": standing.rank,
          "data-live-podium-place": standing.rank
        }
      );
      const person = liveElement("div", "quizgeist-host-podium-place__person");
      person.append(
        this.avatar(
          standing.avatarKey,
          standing.avatarUrl,
          standing.accessoryKey
        ),
        liveElement("h2", "quizgeist-host-podium-place__name", {
          text: standing.displayName
        }),
        liveElement("p", "quizgeist-host-podium-place__score", {
          text: this.pointsLabel(standing.score)
        })
      );
      const column = liveElement(
        "div",
        "quizgeist-host-podium-place__column mq-anim-podium-rise",
        {
          "aria-hidden": "true",
          text: String(standing.rank)
        }
      );
      place.append(person, column);
      return place;
    }
    avatar(avatarKey, avatarUrl, accessoryKey) {
      const frame = liveElement("span", "quizgeist-host-avatar");
      if (avatarUrl) {
        frame.append(
          liveElement("img", "quizgeist-host-avatar__image", {
            alt: "",
            src: avatarUrl
          })
        );
      } else {
        frame.append(createAvatar({ accessoryKey, avatarKey }));
      }
      return frame;
    }
    teamPodiumPlace(standing) {
      const key = standing.teamKey || String(standing.id || "");
      const place = liveElement(
        "section",
        `quizgeist-host-podium-place quizgeist-host-podium-place--${standing.rank}`,
        {
          "data-live-team-podium-place": standing.rank,
          "data-rank": standing.rank,
          "data-team-key": key
        }
      );
      const person = liveElement("div", "quizgeist-host-podium-place__person");
      person.append(
        liveElement("span", "quizgeist-host-podium-place__teammark", {
          "aria-hidden": "true",
          text: "\u2726"
        }),
        liveElement("h2", "quizgeist-host-podium-place__name", {
          text: standing.teamName || standing.name || key
        }),
        liveElement("p", "quizgeist-host-podium-place__score", {
          text: this.pointsLabel(standing.score)
        })
      );
      const column = liveElement(
        "div",
        "quizgeist-host-podium-place__column mq-anim-podium-rise",
        { "aria-hidden": "true", text: String(standing.rank) }
      );
      place.append(person, column);
      return place;
    }
    createConfetti() {
      const confetti = liveElement("div", "quizgeist-host-confetti", {
        "aria-hidden": "true"
      });
      if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        return confetti;
      }
      for (let index = 0; index < 60; index += 1) {
        const particle = liveElement(
          "span",
          `mq-anim-confetti-particle quizgeist-host-confetti__particle quizgeist-host-confetti__particle--${CONFETTI_COLORS[index % CONFETTI_COLORS.length]}`
        );
        particle.style.setProperty("--mq-confetti-x", `${index * 37 % 101}%`);
        particle.style.setProperty("--mq-confetti-delay", `${index * 73 % 1450}ms`);
        particle.style.setProperty("--mq-confetti-duration", `${2200 + index * 97 % 1400}ms`);
        particle.style.setProperty("--mq-confetti-rotation", `${360 + index * 53 % 720}deg`);
        confetti.append(particle);
      }
      return confetti;
    }
    reconcilePlayers(container, players) {
      const existing = /* @__PURE__ */ new Map();
      container.querySelectorAll("[data-live-player-id]").forEach((node) => {
        existing.set(Number(node.dataset.livePlayerId), node);
      });
      const ordered = [];
      players.forEach((player) => {
        let tile = existing.get(player.id);
        if (!tile) {
          tile = this.playerTile(player);
        } else {
          setNodeText(tile, ".quizgeist-host-player__name", player.displayName);
          tile.classList.toggle("is-away", player.status === "away");
        }
        ordered.push(tile);
        existing.delete(player.id);
      });
      existing.forEach((node) => node.remove());
      container.append(...ordered);
      if (players.length === 0) {
        if (!container.querySelector("[data-live-player-empty]")) {
          container.append(
            liveElement("p", "quizgeist-host-player-grid__empty", {
              text: this.text("host:lobby:empty", "Warte auf die ersten Mitspieler \u2026"),
              "data-live-player-empty": true
            })
          );
        }
      } else {
        container.querySelectorAll("[data-live-player-empty]").forEach((node) => {
          node.remove();
        });
      }
    }
    playerTile(player) {
      const tile = liveElement("div", "quizgeist-host-player", {
        "data-live-player-id": player.id
      });
      tile.classList.toggle("is-away", player.status === "away");
      tile.append(
        this.avatar(player.avatarKey, player.avatarUrl, player.accessoryKey),
        liveElement("span", "quizgeist-host-player__name", {
          text: player.displayName
        })
      );
      return tile;
    }
    patchCurrentScreen() {
      var _a;
      const state = this.state;
      if (!state || !this.stage) {
        return;
      }
      const expectedKey = this.screenKey(state);
      if (expectedKey !== this.currentScreenKey) {
        this.currentScreenKey = expectedKey;
        this.renderState();
        return;
      }
      setNodeText(
        this.stage,
        "[data-live-player-count]",
        this.playerCountLabel(state.playerCount)
      );
      setNodeText(
        this.stage,
        "[data-live-answer-count]",
        this.answeredLabel(state)
      );
      const playerGrid = this.stage.querySelector("[data-live-player-grid]");
      if (playerGrid) {
        this.reconcilePlayers(playerGrid, state.players);
      }
      const aggregate = this.stage.querySelector("[data-live-aggregate-slot]");
      if (aggregate && state.question) {
        const source = state.aggregate || (state.phase === "reveal" ? state.distribution : null);
        aggregate.replaceChildren(...source ? [renderLiveAggregate(
          state.question,
          source,
          {
            aggregate: state.aggregate,
            answer: null,
            audience: "host",
            disabled: this.commandBusy,
            interactive: false,
            nowMs: this.serverNow(),
            ...state.phase === "question" ? {
              onAggregateAction: (data2) => {
                this.submitAggregateInteraction(
                  state.question,
                  data2
                );
              }
            } : {},
            text: (key, fallback, values = {}) => this.text(key, fallback, values)
          }
        )] : []);
      }
      const brainstormGrouping = this.stage.querySelector(
        "[data-live-brainstorm-grouping]"
      );
      if (brainstormGrouping && ((_a = state.question) == null ? void 0 : _a.qtype) === "brainstorm" && state.interactionStage === "group") {
        const renderedIdeaIds = Array.from(
          brainstormGrouping.querySelectorAll(
            "[data-live-brainstorm-idea-assignment]"
          ),
          (node) => String(node.dataset.liveBrainstormIdeaAssignment || "")
        ).filter(Boolean).sort();
        const authoritativeIdeaIds = this.brainstormGroups(state).flatMap((group) => group.ideas.map((idea) => idea.id)).sort();
        if (JSON.stringify(renderedIdeaIds) !== JSON.stringify(authoritativeIdeaIds)) {
          brainstormGrouping.replaceWith(this.renderBrainstormGrouping(state));
        }
      }
      const ranking = this.stage.querySelector("[data-live-ranking]");
      if (ranking) {
        this.renderRanking(ranking, state);
      }
      const start = this.stage.querySelector('[data-action="start"]');
      if (start) {
        start.disabled = this.commandBusy || state.playerCount <= 0;
      }
      this.patchTimer();
    }
    tickClock() {
      const state = this.state;
      if (!state || !this.stage) {
        return;
      }
      const key = this.screenKey(state);
      if (key !== this.currentScreenKey) {
        const wasCountdown = this.currentScreenKey.startsWith("countdown:");
        this.currentScreenKey = key;
        this.renderState();
        if (wasCountdown && key.startsWith("question:")) {
          this.sound.play("go");
        }
        return;
      }
      if (this.derivedScreen(state) === "countdown") {
        const seconds = this.countdownSeconds(state);
        setNodeText(this.stage, "[data-live-countdown]", seconds);
        if (seconds !== this.lastTimerAnnouncement) {
          this.lastTimerAnnouncement = seconds;
          this.sound.play("countdown");
          setNodeText(
            this.stage,
            "[data-live-countdown-status]",
            String(seconds)
          );
        }
        return;
      }
      this.patchTimer();
    }
    patchTimer() {
      var _a;
      const state = this.state;
      const timer = (_a = this.stage) == null ? void 0 : _a.querySelector("[data-live-timer]");
      if (this.stage) {
        patchLiveResponseClock(this.stage, this.serverNow());
      }
      if (!state || !timer || state.phaseEndsAtMs <= 0) {
        return;
      }
      const now = this.serverNow();
      const remainingMs = Math.max(0, state.phaseEndsAtMs - now);
      const duration = Math.max(1, state.phaseEndsAtMs - state.phaseStartedAtMs);
      const progress = Math.max(0, Math.min(1, remainingMs / duration));
      const seconds = Math.max(0, Math.ceil(remainingMs / 1e3));
      timer.style.setProperty("--mq-timer-progress", `${progress * 360}deg`);
      timer.classList.toggle("is-warning", seconds <= 5);
      if (seconds > 0 && seconds <= 5 && seconds !== this.lastWarningSecond) {
        this.lastWarningSecond = seconds;
        this.sound.play("warning");
      }
      setNodeText(timer, "[data-live-timer-value]", seconds);
      timer.setAttribute(
        "aria-label",
        this.text(
          "host:question:remaining",
          `${seconds} Sekunden`,
          { a: seconds }
        )
      );
      if (seconds === 5 && this.lastTimerAnnouncement !== 5) {
        this.lastTimerAnnouncement = 5;
        this.announce(
          this.text(
            "host:question:remaining",
            "5 Sekunden",
            { a: 5 }
          )
        );
      }
    }
    brainstormGroups(state) {
      const aggregate = state.aggregate && typeof state.aggregate === "object" ? state.aggregate : {};
      const rawGroups = Array.isArray(aggregate.groups) ? aggregate.groups : [];
      return rawGroups.flatMap((rawGroup) => {
        if (!rawGroup || typeof rawGroup !== "object" || Array.isArray(rawGroup)) {
          return [];
        }
        const group = rawGroup;
        const ideas = Array.isArray(group.ideas) ? group.ideas : [];
        return [{
          ideas: ideas.flatMap((rawIdea) => {
            if (!rawIdea || typeof rawIdea !== "object" || Array.isArray(rawIdea)) {
              return [];
            }
            const idea = rawIdea;
            const id = String(idea.id || "");
            return id === "" ? [] : [{
              id,
              text: String(idea.text || "")
            }];
          }),
          key: String(group.key || ""),
          label: String(group.label || "")
        }];
      }).filter((group) => group.key !== "");
    }
    renderBrainstormGrouping(state) {
      const editor = liveElement("section", "quizgeist-host-brainstorm-editor", {
        "data-live-brainstorm-grouping": true
      });
      editor.append(
        liveElement("h2", "quizgeist-host-section-heading__title", {
          text: this.text("host:action:groupideas", "Ideen gruppieren")
        }),
        liveElement("p", "quizgeist-host-setup__description", {
          text: this.text(
            "host:brainstorm:description",
            "Benennen Sie die Gruppen und ordnen Sie jede Idee genau einer Gruppe zu."
          )
        })
      );
      const groups = this.brainstormGroups(state);
      if (groups.length === 0) {
        groups.push({
          ideas: [],
          key: "group-1",
          label: this.text("host:brainstorm:defaultgroup", "Gruppe {$a}", { a: 1 })
        });
      }
      if (groups.length === 1) {
        groups.push({
          ideas: [],
          key: "group-2",
          label: this.text("host:brainstorm:defaultgroup", "Gruppe {$a}", { a: 2 })
        });
      }
      const labels = liveElement("div", "quizgeist-host-brainstorm-editor__groups");
      const assignments = liveElement("div", "quizgeist-host-brainstorm-editor__ideas");
      const appendGroup = (key, labelText) => {
        const label = liveElement("label", "quizgeist-host-field__label", {
          "data-live-brainstorm-group-editor": key,
          text: this.text("host:brainstorm:groupname", "Gruppenname")
        });
        label.append(liveElement("input", "quizgeist-host-text-input", {
          "data-live-brainstorm-group-label": key,
          maxlength: 80,
          type: "text",
          value: labelText
        }));
        labels.append(label);
      };
      groups.forEach((group, index) => {
        appendGroup(
          group.key,
          group.label || this.text(
            "host:brainstorm:defaultgroup",
            "Gruppe {$a}",
            { a: index + 1 }
          )
        );
      });
      groups.flatMap((group) => group.ideas.map((idea) => ({
        ...idea,
        groupKey: group.key
      }))).forEach((idea) => {
        const row = liveElement("label", "quizgeist-host-brainstorm-editor__idea", {
          "data-live-brainstorm-idea-assignment": idea.id,
          text: idea.text
        });
        const select = liveElement("select", "quizgeist-host-select", {
          "data-live-brainstorm-group-assignment": idea.id
        });
        groups.forEach((group, index) => {
          select.append(liveElement("option", "", {
            text: group.label || this.text(
              "host:brainstorm:defaultgroup",
              "Gruppe {$a}",
              { a: index + 1 }
            ),
            value: group.key
          }));
        });
        select.value = idea.groupKey;
        row.append(select);
        assignments.append(row);
      });
      const actions = liveElement("div", "quizgeist-host-controlbar__actions");
      const add = liveButton(
        this.text("host:brainstorm:addgroup", "Gruppe hinzuf\xFCgen"),
        "quizgeist-host-button quizgeist-host-button--secondary",
        { "data-live-brainstorm-add-group": true }
      );
      add.addEventListener("click", () => {
        const index = labels.childElementCount + 1;
        const key = `group-${index}`;
        const label = this.text(
          "host:brainstorm:defaultgroup",
          "Gruppe {$a}",
          { a: index }
        );
        appendGroup(key, label);
        assignments.querySelectorAll(
          "[data-live-brainstorm-group-assignment]"
        ).forEach((select) => {
          select.append(liveElement("option", "", { text: label, value: key }));
        });
      });
      const save = liveButton(
        this.text("host:brainstorm:savegroups", "Gruppierung speichern"),
        "quizgeist-host-button quizgeist-host-button--secondary",
        {
          "aria-busy": this.commandBusy ? "true" : "false",
          "data-live-brainstorm-save-groups": true,
          "data-live-host-interaction": true
        }
      );
      save.addEventListener("click", () => {
        var _a;
        const question = (_a = this.state) == null ? void 0 : _a.question;
        const payload = this.readBrainstormGrouping();
        if (question && payload) {
          void this.saveBrainstormGrouping(question, payload);
        }
      });
      actions.append(add, save);
      editor.append(labels, assignments, actions);
      return editor;
    }
    readBrainstormGrouping() {
      var _a;
      const editor = (_a = this.stage) == null ? void 0 : _a.querySelector(
        "[data-live-brainstorm-grouping]"
      );
      if (!editor) {
        return null;
      }
      const groups = Array.from(
        editor.querySelectorAll("[data-live-brainstorm-group-label]")
      ).map((input, index) => ({
        ideaIds: [],
        key: input.dataset.liveBrainstormGroupLabel || `group-${index + 1}`,
        label: input.value.trim() || this.text(
          "host:brainstorm:defaultgroup",
          "Gruppe {$a}",
          { a: index + 1 }
        )
      }));
      const byKey = new Map(groups.map((group) => [group.key, group]));
      editor.querySelectorAll(
        "[data-live-brainstorm-group-assignment]"
      ).forEach((select) => {
        const group = byKey.get(select.value);
        const ideaId = Number(select.dataset.liveBrainstormGroupAssignment || 0);
        if (group && Number.isInteger(ideaId) && ideaId > 0) {
          group.ideaIds.push(ideaId);
        }
      });
      return groups.filter((group) => group.ideaIds.length > 0);
    }
    brainstormGroupingFingerprint(groups) {
      return JSON.stringify(groups.map((group) => ({
        ideaIds: [...group.ideaIds].sort((left, right) => left - right),
        key: group.key,
        label: group.label
      })).sort((left, right) => left.key.localeCompare(right.key)));
    }
    brainstormGroupingMatchesState(state, groups) {
      const aggregate = state.aggregate && typeof state.aggregate === "object" ? state.aggregate : {};
      if (aggregate.groupingCurrent !== true) {
        return false;
      }
      const authoritative = this.brainstormGroups(state).map((group) => ({
        ideaIds: group.ideas.map((idea) => Number(idea.id)).filter(
          (ideaId) => Number.isInteger(ideaId) && ideaId > 0
        ),
        key: group.key,
        label: group.label
      }));
      return this.brainstormGroupingFingerprint(authoritative) === this.brainstormGroupingFingerprint(groups);
    }
    async saveBrainstormGrouping(question, groups) {
      var _a;
      const state = this.state;
      if (state && ((_a = state.question) == null ? void 0 : _a.questionToken) === question.questionToken && this.brainstormGroupingMatchesState(state, groups)) {
        return true;
      }
      return this.sendHostInteraction(
        question,
        "submit",
        { groups },
        "group"
      );
    }
    async advanceInteraction(question, stage) {
      var _a;
      if (stage === "group") {
        const groups = this.readBrainstormGrouping();
        if (groups && groups.length > 0) {
          const saved = await this.saveBrainstormGrouping(question, groups);
          if (!saved) {
            return;
          }
        }
      }
      const current = (_a = this.state) == null ? void 0 : _a.question;
      if (current) {
        await this.sendHostInteraction(current, "advance");
      }
    }
    async sendHostInteraction(question, operation, data2, interactionKind) {
      var _a, _b, _c;
      const state = this.state;
      const questionStage = ((_a = question.policyDescriptor) == null ? void 0 : _a.currentStage) || question.interactionStage || null;
      if (!state || this.commandBusy || ((_b = state.question) == null ? void 0 : _b.questionToken) !== question.questionToken || questionStage !== null && state.interactionStage !== null && questionStage !== state.interactionStage) {
        return false;
      }
      const submissionKey = operation === "submit" ? typeof ((_c = window.crypto) == null ? void 0 : _c.randomUUID) === "function" ? window.crypto.randomUUID() : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 14)}` : null;
      return this.performHostMutation(state, (expectedState) => this.api.post("live_host_interaction", {
        ...data2 ? { data: data2 } : {},
        expectedStateVersion: expectedState.stateVersion,
        ...interactionKind ? { interactionKind } : {},
        operation,
        questionId: question.id,
        questionToken: question.questionToken,
        sessionId: state.sessionId,
        ...submissionKey ? { submissionKey } : {}
      }));
    }
    submitAggregateInteraction(question, data2) {
      const interactionKind = data2.interactionKind === "moderation" ? "moderation" : void 0;
      const payload = { ...data2 };
      delete payload.interactionKind;
      void this.sendHostInteraction(
        question,
        "submit",
        payload,
        interactionKind
      );
    }
    countdownSeconds(state) {
      return Math.max(1, Math.ceil((state.phaseStartedAtMs - this.serverNow()) / 1e3));
    }
    commandButton(command, label, variant, disabled = false) {
      const button = liveButton(
        label,
        `quizgeist-host-button quizgeist-host-button--${variant}`,
        {
          "aria-busy": this.commandBusy ? "true" : "false",
          disabled: disabled || this.commandBusy,
          "data-action": command,
          "data-live-command": true
        }
      );
      button.addEventListener("click", () => {
        void this.sound.unlock();
        this.sound.play("tap");
        void this.sendCommand(command, button);
      });
      return button;
    }
    async sendCommand(command, sourceButton) {
      const state = this.state;
      if (!state || this.commandBusy) {
        return false;
      }
      if (command === "abort" && !await this.confirmAbort(sourceButton)) {
        return false;
      }
      return this.performHostMutation(state, (expectedState) => this.api.post(
        "live_host_command",
        {
          command,
          expectedStateVersion: expectedState.stateVersion,
          sessionId: state.sessionId
        }
      ));
    }
    confirmAbort(sourceButton) {
      if (this.abortDialog) {
        return Promise.resolve(false);
      }
      const prompt = this.text(
        "host:action:abortconfirm",
        "Diese Live-Session wirklich abbrechen?"
      );
      const dialog = liveElement("dialog", "quizgeist-host-dialog", {
        "aria-modal": "true"
      });
      const titleId = `quizgeist-host-abort-title-${this.config.cmid}`;
      const descriptionId = `quizgeist-host-abort-description-${this.config.cmid}`;
      dialog.setAttribute("aria-labelledby", titleId);
      dialog.setAttribute("aria-describedby", descriptionId);
      const title = liveElement("h2", "quizgeist-host-dialog__title", {
        id: titleId,
        text: this.text("host:action:abort", "Session abbrechen")
      });
      const description = liveElement("p", "quizgeist-host-dialog__description", {
        id: descriptionId,
        text: prompt
      });
      const actions = liveElement("div", "quizgeist-host-dialog__actions");
      const confirm = liveButton(
        this.text("host:action:abortconfirmbutton", "Session abbrechen"),
        "quizgeist-host-button quizgeist-host-button--quiet-danger"
      );
      const continueButton = liveButton(
        this.text("host:action:abortcontinue", "Weiterspielen"),
        "quizgeist-host-button quizgeist-host-button--secondary"
      );
      actions.append(confirm, continueButton);
      dialog.append(title, description, actions);
      this.abortDialog = dialog;
      return new Promise((resolve) => {
        let settled = false;
        const finish = (confirmed) => {
          if (settled) {
            return;
          }
          settled = true;
          if (dialog.open) {
            dialog.close();
          }
          dialog.remove();
          if (this.abortDialog === dialog) {
            this.abortDialog = null;
          }
          if (sourceButton == null ? void 0 : sourceButton.isConnected) {
            sourceButton.focus();
          }
          resolve(confirmed);
        };
        confirm.addEventListener("click", () => finish(true));
        continueButton.addEventListener("click", () => finish(false));
        dialog.addEventListener("cancel", (event) => {
          event.preventDefault();
          finish(false);
        });
        dialog.addEventListener("close", () => finish(false));
        this.root.append(dialog);
        try {
          dialog.showModal();
          continueButton.focus();
        } catch (_error) {
          finish(false);
        }
      });
    }
    async performHostMutation(state, request) {
      this.setCommandBusy(true);
      try {
        const outcome = await retryHostMutation({
          applyConflictState: (fresh) => this.applyState(fresh),
          conflictState: (error) => {
            var _a;
            return error instanceof LiveApiError && error.status === 409 && ((_a = error.data) == null ? void 0 : _a.state) ? error.data.state : null;
          },
          currentState: () => this.state,
          initialState: state,
          request
        });
        if (outcome.status !== "success") {
          const message = outcome.status === "conflict-exhausted" ? this.text(
            "live:error:statechanged",
            "Die Session hat sich w\xE4hrend der Aktion mehrfach aktualisiert. Bitte versuchen Sie es erneut."
          ) : this.text(
            "live:error:conflict",
            "Die Session wurde inzwischen an anderer Stelle weitergeschaltet."
          );
          this.showConnectionMessage(message, "warning");
          this.announce(message);
          return false;
        }
        if (!outcome.value.state) {
          throw new LiveApiError(
            this.text("live:error:request", "Die Aktion konnte nicht ausgef\xFChrt werden."),
            "invalid_response"
          );
        }
        this.applyState(outcome.value.state);
        return true;
      } catch (error) {
        if (this.isAuthenticationError(error)) {
          this.renderFatalConnection(error);
        } else {
          this.showConnectionMessage(this.errorMessage(error), "error");
        }
        return false;
      } finally {
        this.setCommandBusy(false);
      }
    }
    async endAndPrepareNewRound() {
      if (!await this.sendCommand("end")) {
        return;
      }
      this.clearStoredSession();
      this.state = null;
      this.poller.stop();
      this.renderSetup();
    }
    setCommandBusy(busy) {
      this.commandBusy = busy;
      this.root.setAttribute("aria-busy", busy ? "true" : "false");
      this.root.querySelectorAll(
        "[data-live-command], [data-live-host-interaction]"
      ).forEach((button) => {
        var _a, _b, _c;
        const action = button.dataset.action;
        const unavailable = action === "start" && ((_a = this.state) == null ? void 0 : _a.playerCount) === 0 || action === "previous" && ((_c = (_b = this.state) == null ? void 0 : _b.currentIndex) != null ? _c : -1) <= 0 || (button.dataset.liveWordcloudModerate !== void 0 || button.dataset.liveBrainstormModerate !== void 0) && button.getAttribute("aria-pressed") === "true";
        button.disabled = busy || unavailable;
        button.setAttribute("aria-busy", busy ? "true" : "false");
      });
    }
    storedSessionId() {
      try {
        const value2 = window.sessionStorage.getItem(this.reconnectKey);
        return value2 && /^[1-9][0-9]*$/.test(value2) ? Number(value2) : null;
      } catch (_error) {
        return null;
      }
    }
    storeSession(sessionId) {
      try {
        window.sessionStorage.setItem(this.reconnectKey, String(sessionId));
      } catch (_error) {
      }
    }
    clearStoredSession() {
      try {
        window.sessionStorage.removeItem(this.reconnectKey);
      } catch (_error) {
      }
    }
    showConnectionMessage(message, tone) {
      if (!this.connectionBanner) {
        return;
      }
      this.connectionBanner.hidden = false;
      this.connectionBanner.dataset.tone = tone;
      this.connectionBanner.textContent = message;
    }
    markConnectionRestored() {
      if (!this.connectionBanner) {
        return;
      }
      if (this.connectionTroubled) {
        this.connectionTroubled = false;
        this.showConnectionMessage(
          this.text("live:connection:restored", "Wieder verbunden."),
          "success"
        );
        window.setTimeout(() => {
          var _a;
          if (((_a = this.connectionBanner) == null ? void 0 : _a.dataset.tone) === "success") {
            this.connectionBanner.hidden = true;
            this.connectionBanner.textContent = "";
          }
        }, 1800);
        return;
      }
      this.connectionBanner.hidden = true;
      this.connectionBanner.textContent = "";
    }
    isAuthenticationError(error) {
      return error instanceof LiveApiError && (error.status === 401 || error.status === 403);
    }
    errorMessage(error) {
      if (error instanceof LiveApiError && error.message !== "") {
        return error.message;
      }
      if (error instanceof Error && error.message !== "") {
        return error.message;
      }
      return this.text(
        "live:error:request",
        "Die Live-Session konnte die Anfrage nicht verarbeiten."
      );
    }
    joinUrl(joinCode) {
      const url = new URL(this.config.playerUrlBase, window.location.href);
      url.searchParams.set("code", joinCode);
      return url.toString();
    }
    phaseLabel(phase) {
      if (phase === "aborted") {
        return this.text("host:action:abort", "Session abgebrochen");
      }
      const fallback = {
        ended: "Beendet",
        lobby: "Lobby",
        podium: "Podium",
        question: "Frage",
        reveal: "Aufl\xF6sung",
        scoreboard: "Zwischenstand"
      };
      return this.text(`live:phase:${phase}`, fallback[phase] || "Live-Session");
    }
    nameModeLabel(nameMode) {
      const fallback = {
        custom: "Eigene Spitznamen",
        generated: "Lustige Namen erzeugen",
        real: "Echte Namen"
      };
      return this.text(
        `host:namemode:${nameMode}`,
        fallback[nameMode] || "Teilnehmernamen"
      );
    }
    modeLabel(mode) {
      const fallback = {
        accuracy: "Genauigkeit",
        classic: "Klassisch",
        security: "Sicherheit",
        team: "Teammodus"
      };
      return this.text(`mode:${mode}`, fallback[mode] || "Spielmodus");
    }
    questionTypeLabel(question) {
      const fallback = {
        brainstorm: "Brainstorming",
        open: "Offene Frage",
        pin: "Pin platzieren",
        poll: "Umfrage",
        puzzle: "Puzzle",
        quiz: "Quiz",
        reveal: "Bild aufdecken",
        scale: "Skala",
        shortanswer: "Antwort eingeben",
        slide: "Inhaltsfolie",
        slider: "Schieberegler",
        truefalse: "Wahr oder falsch",
        wordcloud: "Wortwolke"
      };
      return this.text(
        `live:qtype:${question.qtype}`,
        fallback[question.qtype] || "Frage"
      );
    }
    questionProgress(question, state) {
      const current = question.index + 1;
      const total = question.total > 0 ? question.total : state.totalQuestions;
      return this.text(
        "host:question:progress",
        `Frage ${current} von ${total}`,
        { current, total }
      );
    }
    answeredLabel(state) {
      return this.text(
        "host:question:answered",
        `${state.answerCount} / ${state.playerCount} haben geantwortet`,
        {
          answered: state.answerCount,
          players: state.playerCount
        }
      );
    }
    playerCountLabel(playerCount) {
      return `${playerCount} ${this.text("host:lobby:players", "Mitspieler")}`;
    }
    pointsLabel(points) {
      const locale = document.documentElement.lang || void 0;
      const value2 = Math.max(0, Math.round(Number(points || 0)));
      return `${value2.toLocaleString(locale)} ${this.text("live:points", "Punkte")}`;
    }
  };

  // src/live/types.ts
  function isHostConfig(config) {
    return typeof config.ajaxUrl === "string" && config.ajaxUrl !== "" && typeof config.brandIconUrl === "string" && config.brandIconUrl !== "" && typeof config.cmid === "number" && Number.isInteger(config.cmid) && config.cmid > 0 && typeof config.containerId === "string" && config.containerId !== "" && typeof config.playerUrlBase === "string" && config.playerUrlBase !== "" && typeof config.sesskey === "string" && config.sesskey !== "" && typeof config.strings === "object" && config.strings !== null;
  }

  // src/app_host.ts
  var activeApp = null;
  function init(config = {}) {
    var _a;
    const containerId = config.containerId || "quizgeist-app-host";
    const root = document.getElementById(containerId);
    if (!root) {
      return;
    }
    const hostConfig = { ...config, containerId };
    activeApp == null ? void 0 : activeApp.destroy();
    activeApp = null;
    if (!isHostConfig(hostConfig)) {
      root.dataset.quizgeistRoot = "host";
      root.classList.add("quizgeist-host-root");
      const error = liveElement("div", "quizgeist-host-state-card quizgeist-host-state-card--error", {
        role: "alert"
      });
      error.append(
        liveElement("h1", "quizgeist-host-state-card__title", {
          text: ((_a = config.strings) == null ? void 0 : _a["live:error:config"]) || "Die Live-Ansicht konnte nicht gestartet werden."
        })
      );
      root.replaceChildren(error);
      return;
    }
    activeApp = new HostApp(root, hostConfig);
    void activeApp.init();
  }
  return __toCommonJS(app_host_exports);
})();
