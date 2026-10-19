#!/usr/bin/env bash
# Builds OpenCV for the iOS app's scan pipeline (DESIGN §33): the same
# release the Android app uses (org.opencv:opencv in app/android/app/
# build.gradle.kts), as opencv2.xcframework with device arm64 and simulator
# arm64 + x86_64 slices. OpenCV's own iOS download has no arm64 simulator
# slice, so it cannot run in the simulator of an Apple Silicon Mac.
#
#   tools/build-opencv-ios.sh        # about 20-30 minutes the first time
#
# Output: app/ios/opencv/opencv2.xcframework (not committed). The Podfile
# links it when present; without it the iOS app builds with the scan
# pipeline off, as on the web. Needs Xcode, cmake and ninja (Homebrew).
set -euo pipefail

VERSION=4.14.0
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/app/ios/opencv"
WORK="${OPENCV_WORK_DIR:-$HOME/Library/Caches/eduvision-opencv-$VERSION}"

for tool in cmake ninja xcodebuild python3 git; do
  command -v "$tool" >/dev/null || { echo "$tool is missing" >&2; exit 1; }
done

# A git checkout, not the release tarball: OpenCV's build scripts ask git
# for the version.
mkdir -p "$WORK"
if [ ! -d "$WORK/opencv-$VERSION/.git" ]; then
  rm -rf "$WORK/opencv-$VERSION"
  echo "cloning OpenCV $VERSION"
  git clone --quiet --depth 1 --branch "$VERSION" https://github.com/opencv/opencv.git "$WORK/opencv-$VERSION"
fi
# ...and on a branch: they refuse a detached HEAD.
if [ -z "$(git -C "$WORK/opencv-$VERSION" branch --show-current)" ]; then
  git -C "$WORK/opencv-$VERSION" switch --quiet -c "build-$VERSION"
fi

# Only what ScanPipeline uses: core, imgproc, imgcodecs (WebP crops),
# objdetect (ArUco markers) and its calib3d/features2d/flann dependencies,
# plus the Objective-C/Swift bindings (objc).
rm -rf "$WORK/build"
python3 "$WORK/opencv-$VERSION/platforms/apple/build_xcframework.py" \
  --out "$WORK/build" \
  --iphoneos_archs arm64 \
  --iphonesimulator_archs arm64,x86_64 \
  --build_only_specified_archs \
  --iphoneos_deployment_target 15.0 \
  --without dnn --without gapi --without highgui --without ml --without photo \
  --without stitching --without video --without videoio

rm -rf "$OUT/opencv2.xcframework"
mkdir -p "$OUT"
cp -R "$WORK/build/opencv2.xcframework" "$OUT/"
echo "$VERSION" > "$OUT/VERSION"
echo
echo "built $OUT/opencv2.xcframework ($(du -sh "$OUT/opencv2.xcframework" | cut -f1)); now: cd app/ios && pod install"
