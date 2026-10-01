package com.eduvision.eduvision

import com.eduvision.eduvision.scan.DocumentPageApi
import com.eduvision.eduvision.scan.DocumentPageRenderer
import com.eduvision.eduvision.scan.ScanPipelineApi
import com.eduvision.eduvision.scan.ScanPipelineImpl
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine

class MainActivity : FlutterActivity() {
    private var scanPipeline: ScanPipelineImpl? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        val pipeline = ScanPipelineImpl(applicationContext)
        scanPipeline = pipeline
        ScanPipelineApi.setUp(flutterEngine.dartExecutor.binaryMessenger, pipeline)
        DocumentPageApi.setUp(
            flutterEngine.dartExecutor.binaryMessenger,
            DocumentPageRenderer(applicationContext),
        )
    }

    override fun cleanUpFlutterEngine(flutterEngine: FlutterEngine) {
        ScanPipelineApi.setUp(flutterEngine.dartExecutor.binaryMessenger, null)
        DocumentPageApi.setUp(flutterEngine.dartExecutor.binaryMessenger, null)
        scanPipeline?.close()
        scanPipeline = null
        super.cleanUpFlutterEngine(flutterEngine)
    }
}
