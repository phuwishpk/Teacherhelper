// OpenCV first: its headers must not see Apple's macros.
#include <opencv2/core.hpp>
#include <opencv2/imgcodecs.hpp>
#include <opencv2/imgproc.hpp>
#include <opencv2/objdetect/aruco_detector.hpp>

#import "ScanCore.h"

#import <CoreGraphics/CoreGraphics.h>
#import <CoreImage/CoreImage.h>
#import <ImageIO/ImageIO.h>
#import <Vision/Vision.h>

#include <algorithm>
#include <climits>
#include <cmath>
#include <memory>
#include <optional>
#include <string>
#include <utility>
#include <vector>

// Line-for-line port of the Android pipeline (ScanPipelineImpl.kt,
// RegionMath.kt, AnswerSheet.kt, LayoutPage.kt, AnswerSheetPageParser.kt).
// Function names follow the Kotlin ones so the two can be compared.

NSErrorDomain const EVScanErrorDomain = @"eduvision.scan";
NSString *const EVScanErrorCodeKey = @"code";

@implementation EVPageDetection
@end
@implementation EVRegionCrop
@end
@implementation EVPageCrops
@end
@implementation EVFrameDetection
@end

namespace {

// ------------------------------------------------------------------ errors

/// FlutterError of the Kotlin code: [code] becomes the Pigeon error code.
struct ScanFailure {
  std::string code;
  std::string message;
};

[[noreturn]] void fail(const std::string &code, const std::string &message) {
  throw ScanFailure{code, message};
}

// --------------------------------------------------------------- RegionMath

constexpr double DPI = 200.0;
constexpr double MM_PER_INCH = 25.4;
constexpr double CROP_MARGIN = 0.02;
constexpr int WARPED_PAGE_LONG_SIDE = 1600;
constexpr int CROP_UPLOAD_LONG_SIDE = 768;
constexpr double INNER_BUBBLE = 0.7;
constexpr double BORDER_INSET_MM = 1.2;
constexpr double MAX_INSET_SHARE = 0.15;
constexpr double NUMBER_GUTTER_MM = 8.0;
constexpr double INK_REACH_MM = 2.0;
constexpr double MIN_SPECK_MM2 = 0.25;
constexpr double MAX_RULE_RUN_MM = 20.0;
constexpr double DEFAULT_FRAME_W_MM = 178.0;
constexpr double DEFAULT_FRAME_H_MM = 265.0;
constexpr int CNN_HEIGHT = 32;
constexpr int CNN_WIDTH = 128;

// ScanPipelineImpl companion.
constexpr int DETECT_LONG_SIDE = 1600;
constexpr int QR_LONG_SIDE = 2000;
constexpr int WEBP_QUALITY = 80;
constexpr double PAPER = 255.0;
constexpr double PAPER_PERCENTILE = 0.9;
constexpr NSTimeInterval STALE_OUTPUT_SECONDS = 24 * 60 * 60;

// AnswerSheetReader.MAX_THRESHOLD.
constexpr double MAX_SHEET_THRESHOLD = 140.0;

/// kotlin.math.round: ties to the even integer (the default rounding mode).
int kround(double x) { return static_cast<int>(std::nearbyint(x)); }

/// Kotlin roundToInt (Math.round): ties toward positive infinity.
int roundToInt(double x) { return static_cast<int>(std::floor(x + 0.5)); }

int clampInt(int v, int lo, int hi) { return std::min(std::max(v, lo), hi); }

struct PixelRect {
  int left, top, right, bottom;
  int width() const { return right - left; }
  int height() const { return bottom - top; }
  bool empty() const { return width() <= 0 || height() <= 0; }
  cv::Rect cv() const { return cv::Rect(left, top, width(), height()); }
};

struct NormRect {
  double x, y, w, h;
};

struct PixelCircle {
  double cx, cy, r;
};

struct PixelSize {
  int width, height;
};

PixelSize warpedSize(double frameWmm, double frameHmm) {
  if (!(frameWmm > 0 && frameHmm > 0)) fail("layout_invalid", "frame must be positive");
  return {kround(frameWmm / MM_PER_INCH * DPI), kround(frameHmm / MM_PER_INCH * DPI)};
}

double mmToPx(double mm) { return mm / MM_PER_INCH * DPI; }

PixelRect cropRect(const NormRect &rect, int width, int height, double margin = CROP_MARGIN) {
  int left = clampInt(static_cast<int>(std::floor((rect.x - margin) * width)), 0, width);
  int top = clampInt(static_cast<int>(std::floor((rect.y - margin) * height)), 0, height);
  int right = clampInt(static_cast<int>(std::ceil((rect.x + rect.w + margin) * width)), 0, width);
  int bottom = clampInt(static_cast<int>(std::ceil((rect.y + rect.h + margin) * height)), 0, height);
  return {left, top, std::max(left, right), std::max(top, bottom)};
}

PixelRect inset(const PixelRect &rect, int insetPx) {
  int dx = std::min(insetPx, static_cast<int>(rect.width() * MAX_INSET_SHARE));
  int dy = std::min(insetPx, static_cast<int>(rect.height() * MAX_INSET_SHARE));
  return {rect.left + dx, rect.top + dy, rect.right - dx, rect.bottom - dy};
}

PixelRect inkRect(const NormRect &rect, int width, int height, int insetPx, int gutterPx) {
  PixelRect outer = cropRect(rect, width, height, 0.0);
  PixelRect inner = inset(outer, insetPx);
  if (gutterPx <= 0) return inner;
  int left = std::min(inner.right, std::max(inner.left, outer.left + gutterPx));
  return {left, inner.top, inner.right, inner.bottom};
}

int ruleRunLength(int width, int maxRunPx) { return std::max(15, std::min(width / 4, maxRunPx)); }

double mm2ToPx(double mm2) {
  double side = mmToPx(1.0);
  return mm2 * side * side;
}

PixelCircle bubbleCircle(double cx, double cy, double r, int width, int height) {
  return {cx * width, cy * height, r * width};
}

PixelSize scaleToLongSide(int width, int height, int longSide) {
  int longest = std::max(width, height);
  if (longest <= longSide) return {width, height};
  double s = static_cast<double>(longSide) / longest;
  return {std::max(1, kround(width * s)), std::max(1, kround(height * s))};
}

PixelSize uploadCropSize(int width, int height) {
  return scaleToLongSide(width, height, CROP_UPLOAD_LONG_SIDE);
}

int cnnStripWidth(int width, int height) {
  if (width <= 0 || height <= 0) return 0;
  int w = std::max(1, kround(static_cast<double>(width) * CNN_HEIGHT / height));
  return std::min(w, CNN_WIDTH);
}

std::optional<PixelRect> tightBox(int inkLeft, int inkTop, int inkRight, int inkBottom, int width, int height,
                                  double marginShare = 0.10) {
  if (inkRight < inkLeft || inkBottom < inkTop) return std::nullopt;
  int pad = kround(marginShare * (inkBottom - inkTop + 1));
  return PixelRect{std::max(0, inkLeft - pad), std::max(0, inkTop - pad), std::min(width, inkRight + 1 + pad),
                   std::min(height, inkBottom + 1 + pad)};
}

double inkThresholdOf(double otsu, double paperLevel, double minContrast = 25.0) {
  return std::min(otsu, paperLevel - minContrast);
}

int percentile(const std::vector<int> &histogram, double share) {
  long long total = 0;
  for (int v : histogram) total += v;
  if (total == 0) return 0;
  double target = share * total;
  long long seen = 0;
  for (size_t level = 0; level < histogram.size(); level++) {
    seen += histogram[level];
    if (seen >= target) return static_cast<int>(level);
  }
  return static_cast<int>(histogram.size()) - 1;
}

bool isClockwiseConvex(const double *points) {
  int sign = 0;
  for (int i = 0; i < 4; i++) {
    double ax = points[2 * i], ay = points[2 * i + 1];
    double bx = points[2 * ((i + 1) % 4)], by = points[2 * ((i + 1) % 4) + 1];
    double cx = points[2 * ((i + 2) % 4)], cy = points[2 * ((i + 2) % 4) + 1];
    double cross = (bx - ax) * (cy - by) - (by - ay) * (cx - bx);
    int s = cross > 0 ? 1 : (cross < 0 ? -1 : 0);
    if (s == 0) return false;
    if (sign == 0) {
      sign = s;
    } else if (s != sign) {
      return false;
    }
  }
  return sign > 0;
}

/// CircleFill.darkShare, reading [gray] directly instead of a copied patch.
double darkShare(const cv::Mat &gray, const PixelRect &box, const PixelCircle &circle, double threshold) {
  double r2 = circle.r * circle.r;
  int inside = 0;
  int dark = 0;
  for (int row = 0; row < box.height(); row++) {
    const uchar *p = gray.ptr<uchar>(box.top + row) + box.left;
    double dy = box.top + row + 0.5 - circle.cy;
    for (int col = 0; col < box.width(); col++) {
      double dx = box.left + col + 0.5 - circle.cx;
      if (dx * dx + dy * dy > r2) continue;
      inside++;
      if (p[col] <= threshold) dark++;
    }
  }
  return inside == 0 ? 0.0 : static_cast<double>(dark) / inside;
}

const int BORDER_INSET_PX = roundToInt(mmToPx(BORDER_INSET_MM));
const int NUMBER_GUTTER_PX = roundToInt(mmToPx(NUMBER_GUTTER_MM));
const int MAX_RULE_RUN_PX = roundToInt(mmToPx(MAX_RULE_RUN_MM));
const double INK_REACH_PX = mmToPx(INK_REACH_MM);
const double MIN_SPECK_AREA_PX = mm2ToPx(MIN_SPECK_MM2);

// ------------------------------------------------------------ layout JSON

NSString *ns(const std::string &s) { return [NSString stringWithUTF8String:s.c_str()]; }
std::string str(NSString *s) { return s.UTF8String ? std::string(s.UTF8String) : std::string(); }

/// JSON readers that fail like org.json's getX (a missing key or a wrong
/// type is `layout_invalid`).
struct Json {
  std::string context;

  [[noreturn]] void bad(const std::string &why) const { fail("layout_invalid", context + why); }

  NSDictionary *object(NSDictionary *o, NSString *key) const {
    id v = o[key];
    if (![v isKindOfClass:NSDictionary.class]) bad("JSONObject[\"" + str(key) + "\"] not found.");
    return v;
  }
  NSArray *array(NSDictionary *o, NSString *key) const {
    id v = o[key];
    if (![v isKindOfClass:NSArray.class]) bad("JSONArray[\"" + str(key) + "\"] not found.");
    return v;
  }
  NSDictionary *objectAt(NSArray *a, NSUInteger i) const {
    id v = a[i];
    if (![v isKindOfClass:NSDictionary.class]) bad("JSONArray[" + std::to_string(i) + "] is not a JSONObject.");
    return v;
  }
  NSString *string(NSDictionary *o, NSString *key) const {
    id v = o[key];
    if (![v isKindOfClass:NSString.class]) bad("JSONObject[\"" + str(key) + "\"] is not a string.");
    return v;
  }
  double number(NSDictionary *o, NSString *key) const {
    id v = o[key];
    if ([v isKindOfClass:NSNumber.class]) return [v doubleValue];
    if ([v isKindOfClass:NSString.class]) {
      NSScanner *scanner = [NSScanner scannerWithString:v];
      double d;
      if ([scanner scanDouble:&d] && scanner.isAtEnd) return d;
    }
    bad("JSONObject[\"" + str(key) + "\"] is not a number.");
  }
  int integer(NSDictionary *o, NSString *key) const { return static_cast<int>(number(o, key)); }
  bool optBool(NSDictionary *o, NSString *key) const {
    id v = o[key];
    if ([v isKindOfClass:NSNumber.class]) return [v boolValue];
    if ([v isKindOfClass:NSString.class]) return [[v lowercaseString] isEqualToString:@"true"];
    return false;
  }
  /// org.json `get(key).toString()`: a string as is, a number in its shortest form.
  std::string scalar(NSDictionary *o, NSString *key) const {
    id v = o[key];
    if ([v isKindOfClass:NSString.class]) return str(v);
    if ([v isKindOfClass:NSNumber.class]) return str([v stringValue]);
    bad("JSONObject[\"" + str(key) + "\"] not found.");
  }
  NormRect rect(NSDictionary *o) const {
    return {number(o, @"x"), number(o, @"y"), number(o, @"w"), number(o, @"h")};
  }
};

NSDictionary *parseRoot(NSString *json, const Json &j) {
  NSData *data = [json dataUsingEncoding:NSUTF8StringEncoding];
  NSError *error = nil;
  id root = data ? [NSJSONSerialization JSONObjectWithData:data options:0 error:&error] : nil;
  if (![root isKindOfClass:NSDictionary.class]) j.bad("A JSONObject text must begin with '{'");
  return root;
}

struct LayoutBubble {
  std::string option;
  double cx, cy, r;
};

struct LayoutFinalAnswer {
  NormRect rect;
  bool numeric;
};

struct LayoutRegion {
  std::string regionId;
  std::string kind;
  NormRect rect;
  bool numeric;
  std::vector<LayoutBubble> bubbles;
  std::optional<LayoutFinalAnswer> finalAnswer;
  bool numbered() const { return kind == "lines" && finalAnswer.has_value(); }
};

struct LayoutPage {
  double frameWmm, frameHmm;
  std::vector<LayoutRegion> regions;
};

const std::string FINAL_SUFFIX = "_final";

LayoutPage parseLayoutPage(NSString *json) {
  Json j{"Layout JSON is malformed: "};
  NSDictionary *root = parseRoot(json, j);
  NSDictionary *frame = j.object(root, @"frame_mm");
  NSArray *regionsJson = j.array(root, @"regions");
  LayoutPage page{};
  for (NSUInteger i = 0; i < regionsJson.count; i++) {
    NSDictionary *r = j.objectAt(regionsJson, i);
    std::string kind = str(j.string(r, @"kind"));
    if (kind != "mcq" && kind != "box" && kind != "lines") j.bad("unknown region kind " + kind);
    LayoutRegion region{};
    if ([r[@"bubbles"] isKindOfClass:NSArray.class]) {
      NSArray *arr = r[@"bubbles"];
      for (NSUInteger b = 0; b < arr.count; b++) {
        NSDictionary *o = j.objectAt(arr, b);
        region.bubbles.push_back({str(j.string(o, @"option")), j.number(o, @"cx"), j.number(o, @"cy"),
                                  j.number(o, @"r")});
      }
    }
    if ([r[@"final_answer"] isKindOfClass:NSDictionary.class]) {
      NSDictionary *fa = r[@"final_answer"];
      region.finalAnswer = LayoutFinalAnswer{j.rect(j.object(fa, @"rect")), j.optBool(fa, @"numeric")};
    }
    region.regionId = str(j.string(r, @"region_id"));
    region.kind = kind;
    region.rect = j.rect(j.object(r, @"rect"));
    region.numeric = j.optBool(r, @"numeric");
    page.regions.push_back(region);
  }
  page.frameWmm = j.number(frame, @"w");
  page.frameHmm = j.number(frame, @"h");
  if (!(page.frameWmm > 0 && page.frameHmm > 0)) j.bad("frame_mm must be positive");
  return page;
}

// ------------------------------------------------------------ answer sheets

struct SheetBubble {
  std::string value;
  double cx, cy, r;
};

struct OmrRow {
  int sheetNo;
  std::vector<SheetBubble> bubbles;
};

struct DigitBlock {
  int sheetNo;
  std::optional<SheetBubble> sign;
  std::vector<std::vector<SheetBubble>> columns;
};

struct AnswerSheetPage {
  double frameWmm, frameHmm;
  NormRect answerArea;
  std::vector<SheetBubble> version;
  std::vector<OmrRow> rows;
  std::vector<DigitBlock> blocks;
};

AnswerSheetPage parseAnswerSheetPage(NSString *json) {
  Json j{"Answer-sheet layout JSON is malformed: "};
  NSDictionary *root = parseRoot(json, j);
  id sheet = root[@"sheet"];
  if (![sheet isKindOfClass:NSString.class] || ![sheet isEqualToString:@"exam"]) {
    j.bad("not an exam answer sheet layout");
  }
  NSDictionary *frame = j.object(root, @"frame_mm");
  double w = j.number(frame, @"w");
  double h = j.number(frame, @"h");
  if (!(w > 0 && h > 0)) j.bad("frame_mm must be positive");
  NSDictionary *area = j.object(root, @"answer_area");

  auto bubble = [&](NSDictionary *o, const std::optional<std::string> &value) {
    return SheetBubble{value ? *value : j.scalar(o, @"value"), j.number(o, @"cx"), j.number(o, @"cy"),
                       j.number(o, @"r")};
  };
  auto bubbles = [&](NSArray *arr) {
    std::vector<SheetBubble> out;
    for (NSUInteger i = 0; i < arr.count; i++) out.push_back(bubble(j.objectAt(arr, i), std::nullopt));
    return out;
  };

  AnswerSheetPage page{};
  page.frameWmm = w;
  page.frameHmm = h;
  NSArray *regions = j.array(root, @"regions");
  for (NSUInteger i = 0; i < regions.count; i++) {
    NSDictionary *r = j.objectAt(regions, i);
    std::string kind = str(j.string(r, @"kind"));
    if (kind == "version_bubbles") {
      page.version = bubbles(j.array(r, @"bubbles"));
    } else if (kind == "omr_row") {
      page.rows.push_back({j.integer(r, @"sheet_no"), bubbles(j.array(r, @"bubbles"))});
    } else if (kind == "digit_block") {
      NSArray *columns = j.array(r, @"columns");
      DigitBlock block{};
      block.sheetNo = j.integer(r, @"sheet_no");
      if ([r[@"sign"] isKindOfClass:NSDictionary.class]) block.sign = bubble(r[@"sign"], std::string("-"));
      for (NSUInteger c = 0; c < columns.count; c++) {
        block.columns.push_back(bubbles(j.array(j.objectAt(columns, c), @"bubbles")));
      }
      page.blocks.push_back(block);
    } else {
      j.bad("unknown region kind " + kind);
    }
  }
  page.answerArea = {j.number(area, @"x"), j.number(area, @"y"), j.number(area, @"w"), j.number(area, @"h")};
  return page;
}

/// A LinkedHashMap: keeps the first position of a key, the last value.
template <typename K, typename V>
struct OrderedMap {
  std::vector<std::pair<K, V>> entries;
  void put(const K &k, const V &v) {
    for (auto &e : entries) {
      if (e.first == k) {
        e.second = v;
        return;
      }
    }
    entries.emplace_back(k, v);
  }
};

using FillMap = OrderedMap<std::string, double>;

struct DigitReading {
  std::optional<double> sign;
  std::vector<FillMap> columns;
};

struct SheetReading {
  double baseline;
  std::optional<FillMap> versionFill;
  OrderedMap<int, FillMap> rows;
  OrderedMap<int, DigitReading> digits;
};

/// AnswerSheetBaseline.of: median of the minima of the non-empty groups.
double baselineOf(const std::vector<std::vector<double>> &groups) {
  std::vector<double> minima;
  for (const auto &g : groups) {
    if (!g.empty()) minima.push_back(*std::min_element(g.begin(), g.end()));
  }
  std::sort(minima.begin(), minima.end());
  if (minima.empty()) return 0.0;
  size_t mid = minima.size() / 2;
  return minima.size() % 2 == 1 ? minima[mid] : (minima[mid - 1] + minima[mid]) / 2;
}

double adjust(double fill, double baseline) {
  if (baseline >= 1.0) return 0.0;
  return std::min(1.0, std::max(0.0, (fill - baseline) / (1 - baseline)));
}

template <typename RawFill>
SheetReading readSheetFills(const AnswerSheetPage &page, RawFill rawFill) {
  using Pairs = std::vector<std::pair<std::string, double>>;
  std::vector<std::vector<double>> groups;
  auto values = [](const Pairs &pairs) {
    std::vector<double> out;
    for (const auto &p : pairs) out.push_back(p.second);
    return out;
  };

  Pairs version;
  for (const auto &b : page.version) version.emplace_back(b.value, rawFill(b));
  if (!version.empty()) groups.push_back(values(version));

  std::vector<std::pair<int, Pairs>> rows;
  for (const auto &row : page.rows) {
    Pairs fills;
    for (const auto &b : row.bubbles) fills.emplace_back(b.value, rawFill(b));
    rows.emplace_back(row.sheetNo, fills);
  }
  for (const auto &row : rows) groups.push_back(values(row.second));

  struct Block {
    int sheetNo;
    std::optional<double> sign;
    std::vector<Pairs> columns;
  };
  std::vector<Block> blocks;
  for (const auto &block : page.blocks) {
    Block out{block.sheetNo, std::nullopt, {}};
    if (block.sign) out.sign = rawFill(*block.sign);
    for (const auto &column : block.columns) {
      Pairs fills;
      for (const auto &b : column) fills.emplace_back(b.value, rawFill(b));
      out.columns.push_back(fills);
    }
    if (out.sign) groups.push_back({*out.sign});
    for (const auto &column : out.columns) groups.push_back(values(column));
    blocks.push_back(out);
  }

  double b = baselineOf(groups);
  auto adj = [&](const Pairs &pairs) {
    FillMap m;
    for (const auto &p : pairs) m.put(p.first, adjust(p.second, b));
    return m;
  };

  SheetReading reading{b, std::nullopt, {}, {}};
  if (!version.empty()) reading.versionFill = adj(version);
  for (const auto &row : rows) reading.rows.put(row.first, adj(row.second));
  for (const auto &block : blocks) {
    DigitReading d;
    if (block.sign) d.sign = adjust(*block.sign, b);
    for (const auto &column : block.columns) d.columns.push_back(adj(column));
    reading.digits.put(block.sheetNo, d);
  }
  return reading;
}

std::string num(double v) {
  if (std::isnan(v) || std::isinf(v)) return "0";
  char buf[64];
  snprintf(buf, sizeof buf, "%.4f", std::nearbyint(v * 10000) / 10000);
  return buf;
}

std::string quote(const std::string &s) {
  std::string out = "\"";
  for (unsigned char c : s) {
    if (c == '"') {
      out += "\\\"";
    } else if (c == '\\') {
      out += "\\\\";
    } else if (c < 0x20) {
      char buf[8];
      snprintf(buf, sizeof buf, "\\u%04x", c);
      out += buf;
    } else {
      out += static_cast<char>(c);
    }
  }
  return out + "\"";
}

std::string fillMap(const FillMap &fill) {
  std::string out = "{";
  for (size_t i = 0; i < fill.entries.size(); i++) {
    if (i > 0) out += ",";
    out += quote(fill.entries[i].first) + ":" + num(fill.entries[i].second);
  }
  return out + "}";
}

std::string sheetJson(const SheetReading &reading, const std::string &warpedPagePath, double blurScore) {
  std::string sb = "{";
  sb += "\"warped_page_path\":" + quote(warpedPagePath) + ",";
  sb += "\"blur_score\":" + num(blurScore) + ",";
  sb += "\"baseline\":" + num(reading.baseline) + ",";
  sb += "\"version_fill\":" + (reading.versionFill ? fillMap(*reading.versionFill) : std::string("null")) + ",";
  sb += "\"rows\":{";
  for (size_t i = 0; i < reading.rows.entries.size(); i++) {
    if (i > 0) sb += ",";
    sb += quote(std::to_string(reading.rows.entries[i].first)) + ":" + fillMap(reading.rows.entries[i].second);
  }
  sb += "},\"digits\":{";
  for (size_t i = 0; i < reading.digits.entries.size(); i++) {
    if (i > 0) sb += ",";
    const DigitReading &block = reading.digits.entries[i].second;
    sb += quote(std::to_string(reading.digits.entries[i].first)) + ":{\"sign\":";
    sb += block.sign ? num(*block.sign) : std::string("null");
    sb += ",\"columns\":[";
    for (size_t c = 0; c < block.columns.size(); c++) {
      if (c > 0) sb += ",";
      sb += fillMap(block.columns[c]);
    }
    sb += "]}";
  }
  sb += "}}";
  return sb;
}

/// FrameMath.nativeWarpSize.
PixelSize nativeWarpSize(const double *points, double frameWmm, double frameHmm) {
  double left = std::hypot(points[6] - points[0], points[7] - points[1]);
  double right = std::hypot(points[4] - points[2], points[5] - points[3]);
  PixelSize full = warpedSize(frameWmm, frameHmm);
  double h = std::min(static_cast<double>(full.height), (left + right) / 2);
  double w = h * frameWmm / frameHmm;
  return {std::max(1, kround(w)), std::max(1, kround(h))};
}

bool isKrucheckQr(NSString *payload) { return [payload hasPrefix:@"EV1."] || [payload hasPrefix:@"EVX1."]; }

// ------------------------------------------------------------ image helpers

cv::Mat resizeBy(const cv::Mat &src, double scale) {
  cv::Mat out;
  cv::resize(src, out, cv::Size(), scale, scale, cv::INTER_AREA);
  return out;
}

cv::Mat resizeTo(const cv::Mat &src, const PixelSize &size) {
  cv::Mat out;
  cv::resize(src, out, cv::Size(size.width, size.height), 0, 0, cv::INTER_AREA);
  return out;
}

cv::Mat sub(const cv::Mat &m, const PixelRect &r) { return m(r.cv()); }

cv::Mat warp(const cv::Mat &src, const double *pts, const PixelSize &size) {
  std::vector<cv::Point2f> from = {
      {static_cast<float>(pts[0]), static_cast<float>(pts[1])},
      {static_cast<float>(pts[2]), static_cast<float>(pts[3])},
      {static_cast<float>(pts[4]), static_cast<float>(pts[5])},
      {static_cast<float>(pts[6]), static_cast<float>(pts[7])},
  };
  float w = static_cast<float>(size.width);
  float h = static_cast<float>(size.height);
  std::vector<cv::Point2f> to = {{0, 0}, {w, 0}, {w, h}, {0, h}};
  cv::Mat m = cv::getPerspectiveTransform(from, to);
  cv::Mat out;
  cv::warpPerspective(src, out, m, cv::Size(size.width, size.height), cv::INTER_LINEAR, cv::BORDER_REPLICATE);
  return out;
}

double laplacianVariance(const cv::Mat &gray) {
  cv::Mat lap;
  cv::Laplacian(gray, lap, CV_64F);
  cv::Scalar mean, stddev;
  cv::meanStdDev(lap, mean, stddev);
  return stddev[0] * stddev[0];
}

/// Decodes what imread cannot (HEIC from the photo library) with ImageIO,
/// the EXIF orientation applied, into BGR.
cv::Mat decodeWithImageIO(NSString *path) {
  NSURL *url = [NSURL fileURLWithPath:path];
  CGImageSourceRef source = CGImageSourceCreateWithURL((__bridge CFURLRef)url, nullptr);
  if (!source) return cv::Mat();
  NSDictionary *props = (__bridge_transfer NSDictionary *)CGImageSourceCopyPropertiesAtIndex(source, 0, nullptr);
  int pw = [props[(__bridge NSString *)kCGImagePropertyPixelWidth] intValue];
  int ph = [props[(__bridge NSString *)kCGImagePropertyPixelHeight] intValue];
  NSDictionary *options = @{
    (__bridge NSString *)kCGImageSourceCreateThumbnailFromImageAlways : @YES,
    (__bridge NSString *)kCGImageSourceCreateThumbnailWithTransform : @YES,
    (__bridge NSString *)kCGImageSourceThumbnailMaxPixelSize : @(std::max(pw, ph)),
  };
  CGImageRef image = CGImageSourceCreateThumbnailAtIndex(source, 0, (__bridge CFDictionaryRef)options);
  CFRelease(source);
  if (!image) return cv::Mat();
  size_t w = CGImageGetWidth(image);
  size_t h = CGImageGetHeight(image);
  cv::Mat rgba(static_cast<int>(h), static_cast<int>(w), CV_8UC4, cv::Scalar(255, 255, 255, 255));
  CGColorSpaceRef space = CGColorSpaceCreateDeviceRGB();
  CGContextRef ctx = CGBitmapContextCreate(rgba.data, w, h, 8, rgba.step[0], space,
                                           (CGBitmapInfo)kCGImageAlphaPremultipliedLast);
  CGColorSpaceRelease(space);
  if (ctx) {
    CGContextDrawImage(ctx, CGRectMake(0, 0, w, h), image);
    CGContextRelease(ctx);
  }
  CGImageRelease(image);
  if (!ctx) return cv::Mat();
  cv::Mat bgr;
  cv::cvtColor(rgba, bgr, cv::COLOR_RGBA2BGR);
  return bgr;
}

cv::Mat readImage(NSString *path) {
  // IMREAD_COLOR applies the EXIF orientation of camera JPEGs.
  cv::Mat m = cv::imread(str(path), cv::IMREAD_COLOR);
  if (m.empty()) m = decodeWithImageIO(path);
  if (m.empty()) fail("image_unreadable", "Cannot decode image " + str(path));
  return m;
}

/// A CGImage of a gray or BGR Mat, for Vision.
CGImageRef cgImage(const cv::Mat &m) {
  cv::Mat src;
  CGColorSpaceRef space;
  CGBitmapInfo info;
  if (m.channels() == 1) {
    src = m.isContinuous() ? m : m.clone();
    space = CGColorSpaceCreateDeviceGray();
    info = (CGBitmapInfo)kCGImageAlphaNone;
  } else {
    cv::cvtColor(m, src, cv::COLOR_BGR2RGBA);
    space = CGColorSpaceCreateDeviceRGB();
    info = (CGBitmapInfo)kCGImageAlphaNoneSkipLast;
  }
  CFDataRef data = CFDataCreate(nullptr, src.data, static_cast<CFIndex>(src.step[0] * src.rows));
  CGDataProviderRef provider = CGDataProviderCreateWithCFData(data);
  CGImageRef image = CGImageCreate(src.cols, src.rows, 8, 8 * src.channels(), src.step[0], space, info, provider,
                                   nullptr, false, kCGRenderingIntentDefault);
  CGDataProviderRelease(provider);
  CFRelease(data);
  CGColorSpaceRelease(space);
  return image;
}

/// The Krucheck payload of [values] when there is one, else the first.
NSString *preferred(NSArray<NSString *> *values) {
  for (NSString *v in values) {
    if (isKrucheckQr(v)) return v;
  }
  return values.firstObject;
}

/// QR reader (ML Kit on Android): Apple Vision, then Core Image when Vision
/// cannot run (the simulator has no inference context for it) or finds
/// nothing. Prefers a worksheet or answer-sheet payload when several QRs
/// are visible.
NSString *readQr(const cv::Mat &image, int longSide) {
  PixelSize size = scaleToLongSide(image.cols, image.rows, longSide);
  cv::Mat src = size.width != image.cols ? resizeTo(image, size) : image;
  @autoreleasepool {
    CGImageRef cg = cgImage(src);
    if (!cg) return nil;
    NSMutableArray<NSString *> *values = [NSMutableArray array];

    VNDetectBarcodesRequest *request = [[VNDetectBarcodesRequest alloc] init];
    request.symbologies = @[ VNBarcodeSymbologyQR ];
    VNImageRequestHandler *handler = [[VNImageRequestHandler alloc] initWithCGImage:cg options:@{}];
    NSError *error = nil;
    if ([handler performRequests:@[ request ] error:&error]) {
      for (VNBarcodeObservation *code in request.results) {
        if (code.payloadStringValue) [values addObject:code.payloadStringValue];
      }
    }
    if (values.count == 0) {
      CIDetector *detector = [CIDetector detectorOfType:CIDetectorTypeQRCode
                                                context:nil
                                                options:@{CIDetectorAccuracy : CIDetectorAccuracyHigh}];
      for (CIFeature *feature in [detector featuresInImage:[CIImage imageWithCGImage:cg]]) {
        NSString *message = [feature isKindOfClass:CIQRCodeFeature.class] ? ((CIQRCodeFeature *)feature).messageString : nil;
        if (message) [values addObject:message];
      }
    }
    CGImageRelease(cg);
    return preferred(values);
  }
}

void writeWebp(const cv::Mat &m, NSString *path) {
  std::vector<int> params = {cv::IMWRITE_WEBP_QUALITY, WEBP_QUALITY};
  if (!cv::imwrite(str(path), m, params)) fail("storage_failed", "Cannot encode " + str(path.lastPathComponent));
}

/// A crop for upload, scaled down to CROP_UPLOAD_LONG_SIDE (DESIGN §21.9).
void writeCropWebp(const cv::Mat &crop, NSString *path) {
  PixelSize size = uploadCropSize(crop.cols, crop.rows);
  if (size.width == crop.cols && size.height == crop.rows) {
    writeWebp(crop, path);
  } else {
    writeWebp(resizeTo(crop, size), path);
  }
}

/// Otsu split of [gray], never closer than 25 levels to the paper.
double inkThreshold(const cv::Mat &gray) {
  cv::Mat tmp;
  double otsu = cv::threshold(gray, tmp, 0, 255, cv::THRESH_BINARY_INV | cv::THRESH_OTSU);
  cv::Mat hist;
  int channels[] = {0};
  int histSize[] = {256};
  float range[] = {0, 256};
  const float *ranges[] = {range};
  cv::calcHist(&gray, 1, channels, cv::Mat(), hist, 1, histSize, ranges);
  std::vector<int> counts(256);
  for (int i = 0; i < 256; i++) counts[i] = static_cast<int>(hist.at<float>(i));
  int paper = percentile(counts, PAPER_PERCENTILE);
  return inkThresholdOf(otsu, static_cast<double>(paper));
}

/// 255 where [gray] <= [threshold] (ink), 0 elsewhere.
cv::Mat binarize(const cv::Mat &gray, double threshold) {
  cv::Mat bin;
  cv::threshold(gray, bin, threshold, 255, cv::THRESH_BINARY_INV);
  return bin;
}

cv::Mat ruledLines(const cv::Mat &bin) {
  double hLen = ruleRunLength(bin.cols, MAX_RULE_RUN_PX);
  double vLen = std::max(15, static_cast<int>(bin.rows * 0.9));
  cv::Mat tolerant;
  cv::dilate(bin, tolerant, cv::getStructuringElement(cv::MORPH_RECT, cv::Size(1, 3)));
  cv::Mat horizontal, vertical;
  cv::morphologyEx(tolerant, horizontal, cv::MORPH_OPEN,
                   cv::getStructuringElement(cv::MORPH_RECT, cv::Size(static_cast<int>(hLen), 1)));
  cv::morphologyEx(bin, vertical, cv::MORPH_OPEN,
                   cv::getStructuringElement(cv::MORPH_RECT, cv::Size(1, static_cast<int>(vLen))));
  cv::Mat lines;
  cv::bitwise_or(horizontal, vertical, lines);
  cv::dilate(lines, lines, cv::getStructuringElement(cv::MORPH_RECT, cv::Size(3, 3)));
  return lines;
}

cv::Mat withoutRuledLines(const cv::Mat &bin) {
  cv::Mat out;
  cv::subtract(bin, ruledLines(bin), out);
  return out;
}

/// Steps 3 and 4 of inkRatio on a binary ink mask (255 = ink).
double inkCoverage(const cv::Mat &ink) {
  int total = ink.rows * ink.cols;
  cv::Mat strokes;
  cv::morphologyEx(ink, strokes, cv::MORPH_OPEN, cv::getStructuringElement(cv::MORPH_RECT, cv::Size(2, 2)),
                   cv::Point(-1, -1), 1, cv::BORDER_CONSTANT, cv::Scalar(0));
  cv::Mat labels, stats, centroids;
  int count = cv::connectedComponentsWithStats(strokes, labels, stats, centroids, 8, CV_32S);
  if (count <= 1) return 0.0;
  std::vector<bool> kept(count);
  bool any = false;
  for (int i = 0; i < count; i++) {
    kept[i] = i > 0 && stats.at<int>(i, cv::CC_STAT_AREA) >= MIN_SPECK_AREA_PX;
    any = any || kept[i];
  }
  if (!any) return 0.0;
  cv::Mat paper(ink.rows, ink.cols, CV_8UC1);
  for (int y = 0; y < ink.rows; y++) {
    const int *l = labels.ptr<int>(y);
    uchar *p = paper.ptr<uchar>(y);
    for (int x = 0; x < ink.cols; x++) p[x] = kept[l[x]] ? 0 : 255;
  }
  cv::Mat distance;
  cv::distanceTransform(paper, distance, cv::DIST_L2, cv::DIST_MASK_PRECISE);
  cv::Mat near;
  cv::compare(distance, cv::Scalar(INK_REACH_PX), near, cv::CMP_LE);
  return total <= 0 ? 0.0 : static_cast<double>(cv::countNonZero(near)) / total;
}

/// `ink_ratio` of [norm] (DESIGN §9.4, §11.8); see ScanPipelineImpl.inkRatio.
double inkRatio(const cv::Mat &gray, const NormRect &norm, const PixelRect &marginRect, int gutterPx = 0) {
  double threshold = inkThreshold(sub(gray, marginRect));
  PixelRect inner = inkRect(norm, gray.cols, gray.rows, BORDER_INSET_PX, gutterPx);
  if (inner.empty()) return 0.0;
  cv::Mat bin = binarize(sub(gray, inner), threshold);
  return inkCoverage(withoutRuledLines(bin));
}

/// 32 x 128 grayscale bytes for the digit reader (ml/train/preprocess.py).
NSData *cnnInput(const cv::Mat &gray, const NormRect &norm) {
  PixelRect box = inset(cropRect(norm, gray.cols, gray.rows, 0.0), BORDER_INSET_PX);
  if (box.empty()) return nil;
  cv::Mat patch = sub(gray, box).clone();

  cv::Mat lines = ruledLines(binarize(patch, inkThreshold(patch)));
  patch.setTo(cv::Scalar(PAPER), lines);

  cv::Mat ink = binarize(patch, inkThreshold(patch));
  int inkPixels = cv::countNonZero(ink);
  cv::Mat strip = patch;
  if (inkPixels > 0 && inkPixels * 2 <= patch.rows * patch.cols) {
    cv::Mat points;
    cv::findNonZero(ink, points);
    cv::Rect r = cv::boundingRect(points);
    auto tight = tightBox(r.x, r.y, r.x + r.width - 1, r.y + r.height - 1, patch.cols, patch.rows);
    if (tight && !tight->empty()) strip = sub(patch, *tight);
  }

  int stripW = cnnStripWidth(strip.cols, strip.rows);
  cv::Mat canvas(CNN_HEIGHT, CNN_WIDTH, CV_8UC1, cv::Scalar(PAPER));
  if (stripW > 0) {
    cv::Mat resized;
    int interpolation = strip.rows > CNN_HEIGHT ? cv::INTER_AREA : cv::INTER_LINEAR;
    cv::resize(strip, resized, cv::Size(stripW, CNN_HEIGHT), 0, 0, interpolation);
    resized.copyTo(canvas(cv::Rect(0, 0, stripW, CNN_HEIGHT)));
  }
  return [NSData dataWithBytes:canvas.data length:CNN_HEIGHT * CNN_WIDTH];
}

/// Dark share inside 70% of one bubble's radius on the warped [gray] frame.
double circleFill(const cv::Mat &gray, double cx, double cy, double r, double threshold) {
  PixelCircle circle = bubbleCircle(cx, cy, r, gray.cols, gray.rows);
  PixelCircle inner{circle.cx, circle.cy, circle.r * INNER_BUBBLE};
  int x0 = clampInt(static_cast<int>(std::floor(inner.cx - inner.r)), 0, gray.cols);
  int x1 = clampInt(static_cast<int>(std::ceil(inner.cx + inner.r)), 0, gray.cols);
  int y0 = clampInt(static_cast<int>(std::floor(inner.cy - inner.r)), 0, gray.rows);
  int y1 = clampInt(static_cast<int>(std::ceil(inner.cy + inner.r)), 0, gray.rows);
  PixelRect box{x0, y0, std::max(x0, x1), std::max(y0, y1)};
  if (box.empty()) return 0.0;
  return darkShare(gray, box, inner, threshold);
}

std::vector<double> cornersOf(NSArray *corners, const char *what) {
  std::vector<double> pts;
  if (corners.count != 8) fail("markers_missing", std::string("All four corner markers are needed to ") + what);
  for (id v in corners) {
    if (![v isKindOfClass:NSNumber.class]) {
      fail("markers_missing", std::string("All four corner markers are needed to ") + what);
    }
    pts.push_back([v doubleValue]);
  }
  return pts;
}

NSString *safeName(const std::string &id) {
  std::string out = id;
  for (char &c : out) {
    bool ok = (c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') || c == '_' || c == '-';
    if (!ok) c = '_';
  }
  return ns(out);
}

}  // namespace

@implementation EVScanCore {
  NSString *_cacheRoot;
  std::unique_ptr<cv::aruco::ArucoDetector> _detector;
}

- (instancetype)initWithCacheDirectory:(NSString *)directory {
  if ((self = [super init])) _cacheRoot = [directory copy];
  return self;
}

// -------------------------------------------------------------- public API

- (EVPageDetection *)detectPage:(NSString *)imagePath error:(NSError **)error {
  return [self guarded:error block:^id { return [self detect:imagePath]; }];
}

- (EVPageCrops *)cropPage:(NSString *)imagePath
                  corners:(NSArray *)corners
               layoutJson:(NSString *)layoutJson
                    error:(NSError **)error {
  return [self guarded:error
                 block:^id {
                   LayoutPage layout = parseLayoutPage(layoutJson);
                   return [self crop:imagePath corners:corners layout:layout];
                 }];
}

- (NSString *)readAnswerSheet:(NSString *)imagePath
                      corners:(NSArray *)corners
                   layoutJson:(NSString *)layoutJson
                        error:(NSError **)error {
  return [self guarded:error
                 block:^id {
                   AnswerSheetPage page = parseAnswerSheetPage(layoutJson);
                   return [self readSheet:imagePath corners:corners page:page];
                 }];
}

- (EVFrameDetection *)detectFrame:(NSData *)yPlane
                            width:(NSInteger)width
                           height:(NSInteger)height
                      bytesPerRow:(NSInteger)bytesPerRow
                            error:(NSError **)error {
  // The rotation is not needed: markers are told apart by their ids and a
  // rotation keeps their clockwise order, and Vision reads a QR at any angle.
  return [self guarded:error
                 block:^id {
                   return [self frame:yPlane width:(int)width height:(int)height bytesPerRow:(int)bytesPerRow];
                 }];
}

- (void)deleteStaleOutputs {
  NSFileManager *fm = NSFileManager.defaultManager;
  NSDate *cutoff = [NSDate dateWithTimeIntervalSinceNow:-STALE_OUTPUT_SECONDS];
  for (NSString *name in [fm contentsOfDirectoryAtPath:_cacheRoot error:nil] ?: @[]) {
    NSString *path = [_cacheRoot stringByAppendingPathComponent:name];
    NSDate *modified = [fm attributesOfItemAtPath:path error:nil][NSFileModificationDate];
    if (modified && [modified compare:cutoff] == NSOrderedAscending) [fm removeItemAtPath:path error:nil];
  }
}

/// Runs [block] and maps failures to NSError, like Kotlin's `guarded`.
- (id)guarded:(NSError **)error block:(id (^)(void))block {
  std::string code;
  std::string message;
  try {
    return block();
  } catch (const ScanFailure &e) {
    code = e.code;
    message = e.message;
  } catch (const cv::Exception &e) {
    NSLog(@"ScanPipeline: OpenCV failure: %s", e.what());
    code = "pipeline_failed";
    message = e.what();
  } catch (const std::bad_alloc &) {
    code = "pipeline_failed";
    message = "Not enough memory for this photo";
  }
  if (error) {
    *error = [NSError errorWithDomain:EVScanErrorDomain
                                 code:1
                             userInfo:@{EVScanErrorCodeKey : ns(code), NSLocalizedDescriptionKey : ns(message)}];
  }
  return nil;
}

// ------------------------------------------------------------------ detect

- (EVPageDetection *)detect:(NSString *)imagePath {
  cv::Mat color = readImage(imagePath);
  cv::Mat gray;
  cv::cvtColor(color, gray, cv::COLOR_BGR2GRAY);

  // Markers are 12 mm wide, so a downscaled copy is enough and much faster;
  // fall back to full resolution when the page is small in the photo.
  double scale = std::min(1.0, static_cast<double>(DETECT_LONG_SIDE) / std::max(gray.cols, gray.rows));
  cv::Mat small = scale < 1.0 ? resizeBy(gray, scale) : gray;
  auto found = [self findMarkers:small scale:scale];
  if (found.size() < 4 && scale < 1.0) {
    auto full = [self findMarkers:gray scale:1.0];
    if (full.size() > found.size()) found = full;
  }

  NSMutableArray *corners = [NSMutableArray array];
  for (int i = 0; i < 8; i++) [corners addObject:NSNull.null];
  for (const auto &entry : found) {
    corners[2 * entry.first] = @(entry.second.x);
    corners[2 * entry.first + 1] = @(entry.second.y);
  }
  NSMutableArray<NSNumber *> *missing = [NSMutableArray array];
  for (int id = 0; id < 4; id++) {
    bool present = false;
    for (const auto &entry : found) present = present || entry.first == id;
    if (!present) [missing addObject:@(id)];
  }

  cv::Mat warpedGray;
  if (missing.count == 0) {
    double pts[8];
    for (int i = 0; i < 8; i++) pts[i] = [corners[i] doubleValue];
    if (isClockwiseConvex(pts)) {
      warpedGray = warp(gray, pts, warpedSize(DEFAULT_FRAME_W_MM, DEFAULT_FRAME_H_MM));
    } else {
      // Four ids but not in page order (two sheets in the photo, or a false
      // detection): nothing trustworthy to warp with.
      NSLog(@"ScanPipeline: marker centres are not a clockwise convex quad");
      missing = [@[ @0, @1, @2, @3 ] mutableCopy];
      for (int i = 0; i < 8; i++) corners[i] = NSNull.null;
    }
  }

  // Blur on the warped frame keeps the score independent of how far away the
  // phone was (DESIGN §6.2 step 4).
  double blur = laplacianVariance(warpedGray.empty() ? small : warpedGray);

  NSString *qr = readQr(color, QR_LONG_SIDE);
  if (!qr && !warpedGray.empty()) qr = readQr(warpedGray, INT_MAX);
  if (!qr && warpedGray.empty() && scale < 1.0) qr = readQr(gray, INT_MAX);

  EVPageDetection *d = [EVPageDetection new];
  d.qrPayload = qr;
  d.markerCorners = corners;
  d.missingMarkerIds = missing;
  d.blurScore = blur;
  return d;
}

/// Marker id (0..3) -> centre in full-resolution pixels.
- (std::vector<std::pair<int, cv::Point2d>>)findMarkers:(const cv::Mat &)image scale:(double)scale {
  std::vector<std::vector<cv::Point2f>> corners;
  std::vector<int> ids;
  [self arucoDetector]->detectMarkers(image, corners, ids);

  std::vector<std::pair<int, std::pair<double, cv::Point2d>>> best;
  for (size_t i = 0; i < ids.size(); i++) {
    int id = ids[i];
    if (id < 0 || id > 3) continue;
    const auto &c = corners[i];
    double cx = 0, cy = 0, area2 = 0;
    for (int k = 0; k < 4; k++) {
      double x = c[k].x, y = c[k].y;
      double nx = c[(k + 1) % 4].x, ny = c[(k + 1) % 4].y;
      cx += x;
      cy += y;
      area2 += x * ny - nx * y;
    }
    double area = std::abs(area2) / 2;
    cv::Point2d centre(cx / 4 / scale, cy / 4 / scale);
    // The same id twice (two sheets in view): keep the larger one.
    bool replaced = false;
    for (auto &b : best) {
      if (b.first == id) {
        if (area > b.second.first) b.second = {area, centre};
        replaced = true;
      }
    }
    if (!replaced) best.push_back({id, {area, centre}});
  }
  std::vector<std::pair<int, cv::Point2d>> out;
  for (const auto &b : best) out.push_back({b.first, b.second.second});
  return out;
}

- (cv::aruco::ArucoDetector *)arucoDetector {
  if (!_detector) {
    cv::aruco::DetectorParameters params;
    params.cornerRefinementMethod = cv::aruco::CORNER_REFINE_SUBPIX;
    _detector = std::make_unique<cv::aruco::ArucoDetector>(
        cv::aruco::getPredefinedDictionary(cv::aruco::DICT_4X4_50), params);
  }
  return _detector.get();
}

// -------------------------------------------------------------------- crop

- (NSString *)newOutputDir {
  NSString *dir = [_cacheRoot stringByAppendingPathComponent:NSUUID.UUID.UUIDString];
  if (![NSFileManager.defaultManager createDirectoryAtPath:dir withIntermediateDirectories:YES attributes:nil error:nil]) {
    fail("storage_failed", "Cannot create " + str(dir));
  }
  return dir;
}

- (EVPageCrops *)crop:(NSString *)imagePath corners:(NSArray *)cornerValues layout:(const LayoutPage &)layout {
  std::vector<double> pts = cornersOf(cornerValues, "crop the page");
  cv::Mat color = readImage(imagePath);
  PixelSize size = warpedSize(layout.frameWmm, layout.frameHmm);
  cv::Mat warped = warp(color, pts.data(), size);
  cv::Mat gray;
  cv::cvtColor(warped, gray, cv::COLOR_BGR2GRAY);
  int w = size.width;
  int h = size.height;

  NSString *outDir = [self newOutputDir];
  NSString *pageFile = [outDir stringByAppendingPathComponent:@"page.webp"];
  writeWebp(resizeTo(warped, scaleToLongSide(w, h, WARPED_PAGE_LONG_SIDE)), pageFile);

  NSMutableArray<EVRegionCrop *> *crops = [NSMutableArray array];
  for (const LayoutRegion &region : layout.regions) {
    PixelRect rect = cropRect(region.rect, w, h);
    if (rect.empty()) fail("layout_invalid", "Region " + region.regionId + " lies outside the marker frame");
    NSString *file = [outDir stringByAppendingPathComponent:[safeName(region.regionId) stringByAppendingString:@".webp"]];
    writeCropWebp(sub(warped, rect), file);

    EVRegionCrop *crop = [EVRegionCrop new];
    crop.regionId = ns(region.regionId);
    crop.imagePath = file;
    if (region.kind == "mcq") {
      crop.inkRatio = 0.0;
      crop.bubbleFill = [self bubbleFill:gray region:region rect:rect];
      [crops addObject:crop];
    } else if (region.kind == "box") {
      crop.inkRatio = inkRatio(gray, region.rect, rect);
      if (region.numeric) crop.cnnInput = cnnInput(gray, region.rect);
      [crops addObject:crop];
    } else {
      crop.inkRatio = inkRatio(gray, region.rect, rect, region.numbered() ? NUMBER_GUTTER_PX : 0);
      [crops addObject:crop];
      if (region.finalAnswer) {
        const LayoutFinalAnswer &fa = *region.finalAnswer;
        PixelRect finalRect = cropRect(fa.rect, w, h);
        if (finalRect.empty()) {
          fail("layout_invalid", "Final answer of " + region.regionId + " lies outside the marker frame");
        }
        std::string id = region.regionId + FINAL_SUFFIX;
        NSString *finalFile = [outDir stringByAppendingPathComponent:[safeName(id) stringByAppendingString:@".webp"]];
        writeCropWebp(sub(warped, finalRect), finalFile);
        EVRegionCrop *final = [EVRegionCrop new];
        final.regionId = ns(id);
        final.imagePath = finalFile;
        final.inkRatio = inkRatio(gray, fa.rect, finalRect);
        if (fa.numeric) final.cnnInput = cnnInput(gray, fa.rect);
        [crops addObject:final];
      }
    }
  }
  EVPageCrops *out = [EVPageCrops new];
  out.warpedPagePath = pageFile;
  out.regions = crops;
  return out;
}

/// Otsu + dark ratio inside 70% of each bubble's radius (DESIGN §6.2 step 6).
- (NSDictionary<NSString *, NSNumber *> *)bubbleFill:(const cv::Mat &)gray
                                              region:(const LayoutRegion &)region
                                                rect:(const PixelRect &)rect {
  // The printed circles and letters make the region bimodal, so Otsu over the
  // whole row finds the ink/paper split.
  double threshold = inkThreshold(sub(gray, rect));
  NSMutableDictionary<NSString *, NSNumber *> *fill = [NSMutableDictionary dictionary];
  for (const LayoutBubble &b : region.bubbles) fill[ns(b.option)] = @(circleFill(gray, b.cx, b.cy, b.r, threshold));
  return fill;
}

// ----------------------------------------------------------- answer sheets

- (NSString *)readSheet:(NSString *)imagePath corners:(NSArray *)cornerValues page:(const AnswerSheetPage &)page {
  std::vector<double> pts = cornersOf(cornerValues, "read the sheet");
  cv::Mat color = readImage(imagePath);
  PixelSize size = warpedSize(page.frameWmm, page.frameHmm);
  cv::Mat warped = warp(color, pts.data(), size);
  cv::Mat gray;
  cv::cvtColor(warped, gray, cv::COLOR_BGR2GRAY);

  PixelRect area = cropRect(page.answerArea, size.width, size.height, 0.0);
  if (area.empty()) fail("layout_invalid", "answer_area lies outside the marker frame");
  cv::Mat tmp;
  double otsu = cv::threshold(sub(gray, area), tmp, 0, 255, cv::THRESH_BINARY_INV | cv::THRESH_OTSU);
  double threshold = std::min(otsu, MAX_SHEET_THRESHOLD);
  SheetReading reading =
      readSheetFills(page, [&](const SheetBubble &b) { return circleFill(gray, b.cx, b.cy, b.r, threshold); });

  NSString *outDir = [self newOutputDir];
  NSString *pageFile = [outDir stringByAppendingPathComponent:@"page.webp"];
  writeWebp(resizeTo(warped, scaleToLongSide(size.width, size.height, WARPED_PAGE_LONG_SIDE)), pageFile);

  return ns(sheetJson(reading, str(pageFile), laplacianVariance(gray)));
}

/// Markers, QR and blur of one camera frame (DESIGN §22.10); nothing is written.
- (EVFrameDetection *)frame:(NSData *)yPlane width:(int)width height:(int)height bytesPerRow:(int)bytesPerRow {
  if (width <= 0 || height <= 0 || bytesPerRow < width ||
      static_cast<long long>(yPlane.length) < static_cast<long long>(bytesPerRow) * (height - 1) + width) {
    fail("frame_invalid", "The Y plane does not match " + std::to_string(width) + "x" + std::to_string(height) +
                              " (" + std::to_string(bytesPerRow) + " bytes per row)");
  }
  cv::Mat gray =
      cv::Mat(height, width, CV_8UC1, const_cast<void *>(yPlane.bytes), static_cast<size_t>(bytesPerRow)).clone();

  double scale = std::min(1.0, static_cast<double>(DETECT_LONG_SIDE) / std::max(width, height));
  cv::Mat small = scale < 1.0 ? resizeBy(gray, scale) : gray;
  auto found = [self findMarkers:small scale:scale];
  double blur = 0.0;
  if (found.size() == 4) {
    double pts[8] = {0};
    for (const auto &entry : found) {
      pts[2 * entry.first] = entry.second.x;
      pts[2 * entry.first + 1] = entry.second.y;
    }
    if (isClockwiseConvex(pts)) {
      PixelSize size = nativeWarpSize(pts, DEFAULT_FRAME_W_MM, DEFAULT_FRAME_H_MM);
      blur = laplacianVariance(warp(gray, pts, size));
    }
  }
  EVFrameDetection *d = [EVFrameDetection new];
  d.markersFound = static_cast<NSInteger>(found.size());
  d.qrPayload = readQr(gray, QR_LONG_SIDE);
  d.blurScore = blur;
  return d;
}

@end
