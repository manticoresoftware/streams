// Host adapters for the Apache-2.0 jslt-js library under Goja.
(function () {
  function URL(value) {
    var fields = __streamsURLParts(String(value));
    if (fields.error) throw new TypeError(fields.error);
    for (var key in fields) this[key] = fields[key];
  }
  globalThis.URL = URL;
  globalThis.Intl = {
    DateTimeFormat: function (_language, options) {
      var zone = (options && options.timeZone) || "UTC";
      this.formatToParts = function (date) {
        var parts = __streamsDateParts(date.getTime(), zone);
        if (parts.error) throw new RangeError(parts.error);
        return Object.keys(parts).map(function (key) {
          return { type: key, value: parts[key] };
        });
      };
      this.format = function (date) {
        return this.formatToParts(date).map(function (part) {
          return part.value;
        }).join("");
      };
    }
  };
})();
