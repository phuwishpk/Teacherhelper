# OpenCV for the iOS scan pipeline (DESIGN §33), the release the Android
# app uses. tools/build-opencv-ios.sh builds opencv2.xcframework next to this
# file; the framework is not committed.
Pod::Spec.new do |s|
  s.name = 'EduvisionOpenCV'
  s.version = '4.14.0'
  s.summary = 'OpenCV core, imgproc, imgcodecs and objdetect built by tools/build-opencv-ios.sh'
  s.homepage = 'https://opencv.org'
  s.license = { :type => 'Apache-2.0' }
  s.author = 'OpenCV team'
  s.source = { :path => '.' }
  s.platform = :ios, '15.0'
  s.vendored_frameworks = 'opencv2.xcframework'
  s.frameworks = 'Accelerate', 'CoreGraphics', 'CoreImage', 'QuartzCore', 'UIKit'
  s.libraries = 'c++', 'z'
end
