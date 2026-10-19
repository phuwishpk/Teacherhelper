// The iOS scan pipeline (DESIGN §6.2, §33): the image processing of the
// Android ScanPipelineImpl.kt, written against OpenCV's C++ API in
// ScanCore.mm, plus Apple Vision for the QR. ScanPipeline.swift adapts it to
// the Pigeon API. Every method runs synchronously; call them from one
// background queue. Keep both platforms in step: a change to a measurement
// on one side must be made on the other.
#import <Foundation/Foundation.h>

NS_ASSUME_NONNULL_BEGIN

/// Domain of every error below; userInfo[EVScanErrorCodeKey] holds the
/// Pigeon error code (markers_missing, layout_invalid, image_unreadable,
/// storage_failed, frame_invalid, pipeline_failed), as on Android.
FOUNDATION_EXPORT NSErrorDomain const EVScanErrorDomain;
FOUNDATION_EXPORT NSString *const EVScanErrorCodeKey;

@interface EVPageDetection : NSObject
@property(nonatomic, copy, nullable) NSString *qrPayload;
/// x0, y0 .. x3, y3: NSNumber, or NSNull for a marker that was not found.
@property(nonatomic, copy) NSArray *markerCorners;
@property(nonatomic, copy) NSArray<NSNumber *> *missingMarkerIds;
@property(nonatomic) double blurScore;
@end

@interface EVRegionCrop : NSObject
@property(nonatomic, copy) NSString *regionId;
@property(nonatomic, copy) NSString *imagePath;
@property(nonatomic) double inkRatio;
@property(nonatomic, copy, nullable) NSDictionary<NSString *, NSNumber *> *bubbleFill;
@property(nonatomic, copy, nullable) NSData *cnnInput;
@end

@interface EVPageCrops : NSObject
@property(nonatomic, copy) NSString *warpedPagePath;
@property(nonatomic, copy) NSArray<EVRegionCrop *> *regions;
@end

@interface EVFrameDetection : NSObject
@property(nonatomic) NSInteger markersFound;
@property(nonatomic, copy, nullable) NSString *qrPayload;
@property(nonatomic) double blurScore;
@end

@interface EVScanCore : NSObject
/// Crops and warped pages are written under [directory]/<uuid>/.
- (instancetype)initWithCacheDirectory:(NSString *)directory NS_DESIGNATED_INITIALIZER;
- (instancetype)init NS_UNAVAILABLE;

- (nullable EVPageDetection *)detectPage:(NSString *)imagePath error:(NSError **)error;

- (nullable EVPageCrops *)cropPage:(NSString *)imagePath
                           corners:(NSArray *)corners
                        layoutJson:(NSString *)layoutJson
                             error:(NSError **)error;

/// JSON as on Android: {warped_page_path, blur_score, baseline,
/// version_fill, rows, digits}.
- (nullable NSString *)readAnswerSheet:(NSString *)imagePath
                               corners:(NSArray *)corners
                            layoutJson:(NSString *)layoutJson
                                 error:(NSError **)error;

/// [yPlane] is the luma plane of a YUV 4:2:0 camera frame.
- (nullable EVFrameDetection *)detectFrame:(NSData *)yPlane
                                     width:(NSInteger)width
                                    height:(NSInteger)height
                               bytesPerRow:(NSInteger)bytesPerRow
                                     error:(NSError **)error;

/// Removes output folders older than a day (crops the Dart side never took).
- (void)deleteStaleOutputs;
@end

NS_ASSUME_NONNULL_END
