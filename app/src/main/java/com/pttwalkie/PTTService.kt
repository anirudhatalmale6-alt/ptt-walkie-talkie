package com.pttwalkie

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Intent
import android.media.session.MediaSession
import android.media.session.PlaybackState
import android.os.Build
import android.os.IBinder
import android.util.Log
import android.view.KeyEvent
import androidx.core.app.NotificationCompat

class PTTService : Service() {

    companion object {
        const val CHANNEL_ID = "ptt_walkie_channel"
        const val NOTIFICATION_ID = 1001
        private const val TAG = "PTTService"
    }

    private var mediaSession: MediaSession? = null

    override fun onCreate() {
        super.onCreate()
        createNotificationChannel()
        setupMediaSession()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val notification = buildNotification("מחובר ופועל ברקע")
        startForeground(NOTIFICATION_ID, notification)
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun setupMediaSession() {
        mediaSession = MediaSession(this, "PTTWalkie").apply {
            val state = PlaybackState.Builder()
                .setActions(
                    PlaybackState.ACTION_PLAY or
                    PlaybackState.ACTION_PAUSE or
                    PlaybackState.ACTION_PLAY_PAUSE
                )
                .setState(PlaybackState.STATE_PLAYING, 0, 1f)
                .build()
            setPlaybackState(state)

            setCallback(object : MediaSession.Callback() {
                override fun onMediaButtonEvent(mediaButtonIntent: Intent): Boolean {
                    val event = mediaButtonIntent.getParcelableExtra<KeyEvent>(Intent.EXTRA_KEY_EVENT)
                        ?: return super.onMediaButtonEvent(mediaButtonIntent)

                    val keyCode = event.keyCode
                    Log.d(TAG, "Media key: code=$keyCode action=${event.action}")

                    if (isPTTKey(keyCode)) {
                        when (event.action) {
                            KeyEvent.ACTION_DOWN -> {
                                if (!PTTEngine.isTransmitting) {
                                    PTTEngine.startTransmit()
                                    updateNotification("משדר...")
                                }
                                return true
                            }
                            KeyEvent.ACTION_UP -> {
                                if (PTTEngine.isTransmitting) {
                                    PTTEngine.stopTransmit()
                                    updateNotification("מחובר ופועל ברקע")
                                }
                                return true
                            }
                        }
                    }
                    return super.onMediaButtonEvent(mediaButtonIntent)
                }

                override fun onPlay() {
                    if (!PTTEngine.isTransmitting) {
                        PTTEngine.startTransmit()
                        updateNotification("משדר...")
                    }
                }

                override fun onPause() {
                    if (PTTEngine.isTransmitting) {
                        PTTEngine.stopTransmit()
                        updateNotification("מחובר ופועל ברקע")
                    }
                }
            })

            isActive = true
        }
    }

    private fun isPTTKey(keyCode: Int): Boolean = PTTEngine.isPTTKey(keyCode)

    fun updateNotification(text: String) {
        val notification = buildNotification(text)
        val manager = getSystemService(NotificationManager::class.java)
        manager.notify(NOTIFICATION_ID, notification)
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                "PTT Walkie Talkie",
                NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = "PTT Walkie Talkie פועל ברקע"
                setShowBadge(false)
            }
            val manager = getSystemService(NotificationManager::class.java)
            manager.createNotificationChannel(channel)
        }
    }

    private fun buildNotification(text: String): Notification {
        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP
        }
        val pendingIntent = PendingIntent.getActivity(
            this, 0, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("PTT Walkie Talkie")
            .setContentText(text)
            .setSmallIcon(android.R.drawable.ic_btn_speak_now)
            .setContentIntent(pendingIntent)
            .setOngoing(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build()
    }

    override fun onDestroy() {
        mediaSession?.isActive = false
        mediaSession?.release()
        mediaSession = null
        super.onDestroy()
    }
}
