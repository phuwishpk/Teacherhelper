// The iOS scan pipeline (DESIGN §33) on a synthetic photo of a worksheet
// (Fixtures/make_scan_fixture.py: markers, QR, a box with "5", an empty box,
// bubble B filled, ruled lines with "x=5" and an empty final-answer box).
#import <UIKit/UIKit.h>
#import <XCTest/XCTest.h>

#import "../Runner/Scan/ScanCore.h"

static NSString *const kQr = @"EV1.123.4567.1.2.K7Q3M2PA";

@interface ScanCoreTests : XCTestCase
@property(nonatomic) EVScanCore *core;
@property(nonatomic, copy) NSString *photo;
@property(nonatomic, copy) NSString *cacheDir;
@end

@implementation ScanCoreTests

- (void)setUp {
  NSBundle *bundle = [NSBundle bundleForClass:self.class];
  self.photo = [bundle pathForResource:@"scan_photo" ofType:@"jpg"];
  XCTAssertNotNil(self.photo);
  self.cacheDir = [NSTemporaryDirectory() stringByAppendingPathComponent:NSUUID.UUID.UUIDString];
  self.core = [[EVScanCore alloc] initWithCacheDirectory:self.cacheDir];
}

- (void)tearDown {
  [NSFileManager.defaultManager removeItemAtPath:self.cacheDir error:nil];
}

- (NSString *)fixture:(NSString *)name {
  NSString *path = [[NSBundle bundleForClass:self.class] pathForResource:name ofType:@"json"];
  return [NSString stringWithContentsOfFile:path encoding:NSUTF8StringEncoding error:nil];
}

- (EVPageDetection *)detection {
  NSError *error = nil;
  EVPageDetection *d = [self.core detectPage:self.photo error:&error];
  XCTAssertNil(error);
  return d;
}

- (NSString *)codeOf:(NSError *)error {
  return error.userInfo[EVScanErrorCodeKey];
}

- (void)testDetectPageFindsMarkersQrAndSharpness {
  EVPageDetection *d = [self detection];
  XCTAssertEqual(d.missingMarkerIds.count, 0u);
  XCTAssertEqual(d.markerCorners.count, 8u);
  for (id v in d.markerCorners) XCTAssertTrue([v isKindOfClass:NSNumber.class]);
  XCTAssertEqualObjects(d.qrPayload, kQr);
  XCTAssertGreaterThan(d.blurScore, 10.0);
}

- (void)testCropPageMeasuresInkBubblesAndDigits {
  EVPageDetection *d = [self detection];
  NSError *error = nil;
  EVPageCrops *crops = [self.core cropPage:self.photo
                                   corners:d.markerCorners
                                layoutJson:[self fixture:@"layout_page"]
                                     error:&error];
  XCTAssertNil(error);

  UIImage *page = [UIImage imageWithContentsOfFile:crops.warpedPagePath];
  XCTAssertNotNil(page, @"the warped page is a readable WebP");
  XCTAssertEqual(MAX(page.size.width * page.scale, page.size.height * page.scale), 1600.0);

  NSMutableDictionary<NSString *, EVRegionCrop *> *byId = [NSMutableDictionary dictionary];
  for (EVRegionCrop *c in crops.regions) {
    byId[c.regionId] = c;
    XCTAssertNotNil([UIImage imageWithContentsOfFile:c.imagePath], @"%@ crop", c.regionId);
  }
  NSArray *ids = [crops.regions valueForKey:@"regionId"];
  XCTAssertEqualObjects(ids, (@[ @"q1", @"q2", @"q3", @"q4", @"q4_final" ]));

  XCTAssertGreaterThan(byId[@"q1"].inkRatio, 0.02, @"the written 5");
  XCTAssertEqual(byId[@"q1"].cnnInput.length, 32u * 128u);
  XCTAssertNil(byId[@"q2"].cnnInput, @"not numeric");
  XCTAssertLessThan(byId[@"q2"].inkRatio, 0.005, @"an empty box");
  XCTAssertGreaterThan(byId[@"q4"].inkRatio, 0.02, @"x=5 on the lines");
  XCTAssertLessThan(byId[@"q4_final"].inkRatio, 0.005, @"an empty final answer");
  XCTAssertEqual(byId[@"q4_final"].cnnInput.length, 32u * 128u);

  NSDictionary<NSString *, NSNumber *> *fill = byId[@"q3"].bubbleFill;
  XCTAssertEqual(byId[@"q3"].inkRatio, 0.0);
  XCTAssertGreaterThan(fill[@"B"].doubleValue, 0.5);
  for (NSString *option in @[ @"A", @"C", @"D" ]) XCTAssertLessThan(fill[option].doubleValue, 0.2, @"%@", option);

  // The digit canvas holds ink on paper: some dark pixels, mostly white.
  const uint8_t *bytes = byId[@"q1"].cnnInput.bytes;
  int dark = 0;
  for (int i = 0; i < 32 * 128; i++) dark += bytes[i] < 128;
  XCTAssertGreaterThan(dark, 20);
  XCTAssertLessThan(dark, 32 * 128 / 2);
}

- (void)testReadAnswerSheetSubtractsTheBaseline {
  EVPageDetection *d = [self detection];
  NSError *error = nil;
  NSString *json = [self.core readAnswerSheet:self.photo
                                      corners:d.markerCorners
                                   layoutJson:[self fixture:@"answer_sheet"]
                                        error:&error];
  XCTAssertNil(error);
  NSDictionary *result = [NSJSONSerialization JSONObjectWithData:[json dataUsingEncoding:NSUTF8StringEncoding]
                                                         options:0
                                                           error:nil];
  XCTAssertTrue([NSFileManager.defaultManager fileExistsAtPath:result[@"warped_page_path"]]);
  XCTAssertEqualObjects(result[@"version_fill"], NSNull.null);
  NSDictionary *row = result[@"rows"][@"1"];
  XCTAssertGreaterThan([row[@"B"] doubleValue], 0.5);
  for (NSString *option in @[ @"A", @"C", @"D" ]) XCTAssertLessThan([row[option] doubleValue], 0.2, @"%@", option);
  XCTAssertEqualObjects(result[@"digits"], @{});
}

/// The luma plane of the photo with padded rows, as a camera delivers it.
- (NSData *)yPlaneWidth:(size_t)width height:(size_t)height bytesPerRow:(size_t)bytesPerRow {
  UIImage *image = [UIImage imageWithContentsOfFile:self.photo];
  NSMutableData *data = [NSMutableData dataWithLength:bytesPerRow * height];
  CGColorSpaceRef gray = CGColorSpaceCreateDeviceGray();
  CGContextRef ctx = CGBitmapContextCreate(data.mutableBytes, width, height, 8, bytesPerRow, gray, kCGImageAlphaNone);
  CGContextDrawImage(ctx, CGRectMake(0, 0, width, height), image.CGImage);
  CGContextRelease(ctx);
  CGColorSpaceRelease(gray);
  return data;
}

- (void)testDetectFrameOnALumaPlane {
  size_t width = 986, height = 1354, bytesPerRow = 1024;
  NSError *error = nil;
  EVFrameDetection *f = [self.core detectFrame:[self yPlaneWidth:width height:height bytesPerRow:bytesPerRow]
                                         width:width
                                        height:height
                                   bytesPerRow:bytesPerRow
                                         error:&error];
  XCTAssertNil(error);
  XCTAssertEqual(f.markersFound, 4);
  XCTAssertEqualObjects(f.qrPayload, kQr);
  XCTAssertGreaterThan(f.blurScore, 0.0);
}

- (void)testErrorsCarryTheAndroidCodes {
  NSError *error = nil;
  NSMutableArray *corners = [[self detection].markerCorners mutableCopy];
  corners[0] = NSNull.null;
  XCTAssertNil([self.core cropPage:self.photo corners:corners layoutJson:[self fixture:@"layout_page"] error:&error]);
  XCTAssertEqualObjects([self codeOf:error], @"markers_missing");

  error = nil;
  XCTAssertNil([self.core cropPage:self.photo corners:[self detection].markerCorners layoutJson:@"{}" error:&error]);
  XCTAssertEqualObjects([self codeOf:error], @"layout_invalid");

  error = nil;
  XCTAssertNil([self.core readAnswerSheet:self.photo
                                  corners:[self detection].markerCorners
                               layoutJson:[self fixture:@"layout_page"]
                                    error:&error]);
  XCTAssertEqualObjects([self codeOf:error], @"layout_invalid", @"a worksheet layout is not an answer sheet");

  error = nil;
  XCTAssertNil([self.core detectPage:@"/nonexistent.jpg" error:&error]);
  XCTAssertEqualObjects([self codeOf:error], @"image_unreadable");

  error = nil;
  XCTAssertNil([self.core detectFrame:[NSMutableData dataWithLength:10] width:100 height:100 bytesPerRow:100 error:&error]);
  XCTAssertEqualObjects([self codeOf:error], @"frame_invalid");
}

@end
