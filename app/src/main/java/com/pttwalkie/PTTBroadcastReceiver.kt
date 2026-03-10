package com.pttwalkie

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.util.Log

/**
 * Catches PTT button broadcasts from Chinese PTT devices (YTCOM ET200, etc.)
 * These devices often send broadcast intents instead of standard KeyEvents
 * when the PTT button is pressed/released.
 */
class PTTBroadcastReceiver : BroadcastReceiver() {

    companion object {
        private const val TAG = "PTTBroadcast"

        // Common PTT broadcast actions from various Chinese PTT device manufacturers
        val PTT_DOWN_ACTIONS = setOf(
            "com.android.action.SIDE_KEY_DOWN",
            "com.xptt.pttbtn.down",
            "ptt.key.down",
            "android.intent.action.PTT.down",
            "com.ptt.BUTTON_DOWN",
            "com.example.pttkey.down",
            "android.media.RINGER_MODE_CHANGED_ACTION_PTT_DOWN",
            "com.hytera.ptt.down",
            "com.inrico.pttpress",
            "com.zello.ptt.down",
            "com.runbo.ptt.down",
            "android.intent.action.SIDE_KEY_DOWN",
            "com.android.pttkey.down",
            "com.ytcom.ptt.down",
            "com.bcom.ptt.down",
            "com.caltta.ptt.down",
            "com.talkpod.ptt.down",
            "com.senhaix.ptt.down",
            "com.poc.ptt.down",
            "com.zetcom.ptt.down"
        )

        val PTT_UP_ACTIONS = setOf(
            "com.android.action.SIDE_KEY_UP",
            "com.xptt.pttbtn.up",
            "ptt.key.up",
            "android.intent.action.PTT.up",
            "com.ptt.BUTTON_UP",
            "com.example.pttkey.up",
            "android.media.RINGER_MODE_CHANGED_ACTION_PTT_UP",
            "com.hytera.ptt.up",
            "com.inrico.pttrelease",
            "com.zello.ptt.up",
            "com.runbo.ptt.up",
            "android.intent.action.SIDE_KEY_UP",
            "com.android.pttkey.up",
            "com.ytcom.ptt.up",
            "com.bcom.ptt.up",
            "com.caltta.ptt.up",
            "com.talkpod.ptt.up",
            "com.senhaix.ptt.up",
            "com.poc.ptt.up",
            "com.zetcom.ptt.up"
        )

        // Some devices use a single action with extras to indicate down/up
        val PTT_TOGGLE_ACTIONS = setOf(
            "com.android.action.SIDE_KEY",
            "com.xptt.pttbtn",
            "ptt.key",
            "android.intent.action.PTT",
            "com.ptt.BUTTON",
            "com.example.pttkey",
            "android.intent.action.SIDE_KEY",
            "com.android.pttkey",
            "com.ytcom.ptt",
            "com.poc.ptt"
        )

        fun createIntentFilter(): IntentFilter {
            return IntentFilter().apply {
                PTT_DOWN_ACTIONS.forEach { addAction(it) }
                PTT_UP_ACTIONS.forEach { addAction(it) }
                PTT_TOGGLE_ACTIONS.forEach { addAction(it) }
                priority = IntentFilter.SYSTEM_HIGH_PRIORITY
            }
        }
    }

    override fun onReceive(context: Context?, intent: Intent?) {
        val action = intent?.action ?: return
        Log.d(TAG, "Received broadcast: $action extras=${intent.extras}")

        // Notify debug callback
        PTTEngine.onBroadcastDebug?.invoke(action)

        if (!PTTEngine.isConnected) return

        when {
            action in PTT_DOWN_ACTIONS -> {
                Log.d(TAG, "PTT DOWN via broadcast: $action")
                if (!PTTEngine.isTransmitting) {
                    PTTEngine.startTransmit()
                }
            }
            action in PTT_UP_ACTIONS -> {
                Log.d(TAG, "PTT UP via broadcast: $action")
                if (PTTEngine.isTransmitting) {
                    PTTEngine.stopTransmit()
                }
            }
            action in PTT_TOGGLE_ACTIONS -> {
                // Check extras for state
                val isDown = intent.getBooleanExtra("key_down", false) ||
                             intent.getBooleanExtra("pressed", false) ||
                             intent.getBooleanExtra("down", false) ||
                             intent.getIntExtra("state", -1) == 1 ||
                             intent.getIntExtra("key_state", -1) == 1

                val isUp = intent.getBooleanExtra("key_up", false) ||
                           intent.getBooleanExtra("released", false) ||
                           intent.getBooleanExtra("up", false) ||
                           intent.getIntExtra("state", -1) == 0 ||
                           intent.getIntExtra("key_state", -1) == 0

                Log.d(TAG, "PTT TOGGLE via broadcast: $action down=$isDown up=$isUp")

                if (isDown && !PTTEngine.isTransmitting) {
                    PTTEngine.startTransmit()
                } else if (isUp && PTTEngine.isTransmitting) {
                    PTTEngine.stopTransmit()
                } else if (!isDown && !isUp) {
                    // No extras — treat as toggle
                    PTTEngine.toggleTransmit()
                }
            }
        }
    }
}
