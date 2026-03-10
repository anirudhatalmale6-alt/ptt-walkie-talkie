package com.pttwalkie

import android.accessibilityservice.AccessibilityService
import android.util.Log
import android.view.KeyEvent
import android.view.accessibility.AccessibilityEvent

class PTTAccessibilityService : AccessibilityService() {

    companion object {
        private const val TAG = "PTTAccessibility"
        var isRunning = false
            private set
    }

    override fun onServiceConnected() {
        super.onServiceConnected()
        isRunning = true
        Log.d(TAG, "Accessibility service connected")
    }

    override fun onKeyEvent(event: KeyEvent): Boolean {
        val keyCode = event.keyCode
        Log.d(TAG, "Key event: code=$keyCode action=${event.action} name=${KeyEvent.keyCodeToString(keyCode)}")

        if (!PTTEngine.isConnected) return super.onKeyEvent(event)

        if (PTTEngine.isPTTKey(keyCode)) {
            when (event.action) {
                KeyEvent.ACTION_DOWN -> {
                    if (!PTTEngine.isTransmitting) {
                        PTTEngine.startTransmit()
                    }
                    return true
                }
                KeyEvent.ACTION_UP -> {
                    if (PTTEngine.isTransmitting) {
                        PTTEngine.stopTransmit()
                    }
                    return true
                }
            }
        }
        return super.onKeyEvent(event)
    }

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        // Not used, but required
    }

    override fun onInterrupt() {
        // Required override
    }

    override fun onDestroy() {
        isRunning = false
        super.onDestroy()
    }
}
