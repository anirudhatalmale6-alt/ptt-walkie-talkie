package com.pttwalkie

import android.content.Context
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioRecord
import android.media.AudioTrack
import android.media.MediaPlayer
import android.media.MediaRecorder
import android.os.PowerManager
import android.util.Base64
import android.util.Log
import android.view.KeyEvent
import kotlinx.coroutines.*
import okhttp3.*
import org.json.JSONObject

/**
 * Singleton engine that manages WebSocket connection, audio recording, and playback.
 * Shared between Activity (UI) and Service (background).
 */
object PTTEngine {

    private const val TAG = "PTTEngine"
    private const val SAMPLE_RATE = 8000
    private const val CHANNEL_IN = AudioFormat.CHANNEL_IN_MONO
    private const val CHANNEL_OUT = AudioFormat.CHANNEL_OUT_MONO
    private const val ENCODING = AudioFormat.ENCODING_PCM_16BIT
    private const val CHUNK_SIZE = 1600

    val RELAY_SERVERS = listOf(
        "wss://namely-celtic-retreat-bull.trycloudflare.com",
        "ws://167.235.196.123:3000",
        "ws://167.235.196.123:4000",
        "ws://167.235.196.123:9000"
    )

    var isConnected = false
        private set
    var isTransmitting = false
        private set
    var currentGroup = ""
        private set

    // Callbacks for UI updates
    var onStatusChanged: ((String, Int) -> Unit)? = null
    var onOnlineCount: ((Int) -> Unit)? = null
    var onTxStartRemote: (() -> Unit)? = null
    var onTxStopRemote: (() -> Unit)? = null
    var onConnected: (() -> Unit)? = null
    var onDisconnected: (() -> Unit)? = null
    var onTransmitChanged: ((Boolean) -> Unit)? = null

    private var webSocket: WebSocket? = null
    private var audioRecord: AudioRecord? = null
    private var audioTrack: AudioTrack? = null
    private var recordJob: Job? = null
    private var wakeLock: PowerManager.WakeLock? = null
    private var serverIndex = 0
    private var beepOn: MediaPlayer? = null
    private var beepOff: MediaPlayer? = null

    private val scope = CoroutineScope(Dispatchers.Main + SupervisorJob())
    private val client = OkHttpClient.Builder()
        .pingInterval(java.time.Duration.ofSeconds(15))
        .build()

    fun init(context: Context) {
        val pm = context.getSystemService(Context.POWER_SERVICE) as PowerManager
        wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "pttwalkie:engine")

        beepOn = MediaPlayer.create(context, R.raw.ptt_on)
        beepOff = MediaPlayer.create(context, R.raw.ptt_off)

        initAudioTrack()
    }

    fun connect(group: String) {
        if (isConnected) return
        currentGroup = group
        serverIndex = 0
        tryConnect(group)
    }

    private fun tryConnect(group: String) {
        if (serverIndex >= RELAY_SERVERS.size) {
            onStatusChanged?.invoke("לא הצליח להתחבר", 0xFFFF5252.toInt())
            onDisconnected?.invoke()
            return
        }

        val serverUrl = RELAY_SERVERS[serverIndex]
        onStatusChanged?.invoke("מתחבר... (${serverIndex + 1}/${RELAY_SERVERS.size})", 0xFFFFAB00.toInt())

        val url = "$serverUrl?group=$group"
        Log.d(TAG, "Trying: $url")
        val request = Request.Builder().url(url).build()

        webSocket = client.newWebSocket(request, object : WebSocketListener() {
            override fun onOpen(ws: WebSocket, response: Response) {
                isConnected = true
                onStatusChanged?.invoke("מחובר ✓  קבוצה $currentGroup", 0xFF4CAF50.toInt())
                onConnected?.invoke()
            }

            override fun onMessage(ws: WebSocket, text: String) {
                try {
                    val json = JSONObject(text)
                    when (json.optString("type")) {
                        "audio" -> {
                            val data = Base64.decode(json.getString("data"), Base64.NO_WRAP)
                            playAudio(data)
                        }
                        "count" -> onOnlineCount?.invoke(json.getInt("count"))
                        "tx_start" -> onTxStartRemote?.invoke()
                        "tx_stop" -> onTxStopRemote?.invoke()
                    }
                } catch (e: Exception) {
                    Log.e(TAG, "Parse error", e)
                }
            }

            override fun onFailure(ws: WebSocket, t: Throwable, response: Response?) {
                Log.e(TAG, "Failed on ${RELAY_SERVERS[serverIndex]}: ${t.message}")
                serverIndex++
                if (serverIndex < RELAY_SERVERS.size) {
                    tryConnect(currentGroup)
                } else {
                    isConnected = false
                    onStatusChanged?.invoke("שגיאה: ${t.message}", 0xFFFF5252.toInt())
                    onDisconnected?.invoke()
                }
            }

            override fun onClosed(ws: WebSocket, code: Int, reason: String) {
                isConnected = false
                onStatusChanged?.invoke("לא מחובר", 0xFFFF5252.toInt())
                onDisconnected?.invoke()
            }
        })
    }

    fun disconnect() {
        stopTransmit()
        webSocket?.close(1000, "disconnect")
        webSocket = null
        isConnected = false
        onStatusChanged?.invoke("לא מחובר", 0xFFFF5252.toInt())
        onDisconnected?.invoke()
    }

    fun startTransmit() {
        if (!isConnected || isTransmitting) return
        isTransmitting = true
        onTransmitChanged?.invoke(true)

        playBeep(beepOn)
        wakeLock?.acquire(120000)

        webSocket?.send(JSONObject().put("type", "tx_start").toString())

        recordJob = scope.launch(Dispatchers.IO) {
            try {
                val bufSize = maxOf(
                    AudioRecord.getMinBufferSize(SAMPLE_RATE, CHANNEL_IN, ENCODING),
                    CHUNK_SIZE
                )
                audioRecord = AudioRecord(
                    MediaRecorder.AudioSource.MIC,
                    SAMPLE_RATE, CHANNEL_IN, ENCODING, bufSize
                )
                if (audioRecord?.state != AudioRecord.STATE_INITIALIZED) {
                    Log.e(TAG, "AudioRecord init failed")
                    return@launch
                }
                audioRecord?.startRecording()
                val buffer = ByteArray(CHUNK_SIZE)

                while (isActive && isTransmitting) {
                    val read = audioRecord?.read(buffer, 0, buffer.size) ?: -1
                    if (read > 0) {
                        val encoded = Base64.encodeToString(buffer, 0, read, Base64.NO_WRAP)
                        webSocket?.send(
                            JSONObject().put("type", "audio").put("data", encoded).toString()
                        )
                    }
                }
            } catch (e: Exception) {
                Log.e(TAG, "Record error", e)
            } finally {
                audioRecord?.stop()
                audioRecord?.release()
                audioRecord = null
            }
        }
    }

    fun stopTransmit() {
        if (!isTransmitting) return
        isTransmitting = false
        onTransmitChanged?.invoke(false)

        playBeep(beepOff)
        recordJob?.cancel()
        recordJob = null

        if (wakeLock?.isHeld == true) wakeLock?.release()

        webSocket?.send(JSONObject().put("type", "tx_stop").toString())
    }

    fun toggleTransmit() {
        if (isTransmitting) stopTransmit() else startTransmit()
    }

    private fun initAudioTrack() {
        val bufSize = AudioTrack.getMinBufferSize(SAMPLE_RATE, CHANNEL_OUT, ENCODING)
        audioTrack = AudioTrack(
            AudioManager.STREAM_MUSIC,
            SAMPLE_RATE, CHANNEL_OUT, ENCODING,
            maxOf(bufSize, CHUNK_SIZE * 3),
            AudioTrack.MODE_STREAM
        )
        audioTrack?.play()
    }

    private fun playAudio(data: ByteArray) {
        try {
            audioTrack?.write(data, 0, data.size)
        } catch (e: Exception) {
            Log.e(TAG, "Play error", e)
        }
    }

    private fun playBeep(mp: MediaPlayer?) {
        try {
            mp?.let {
                if (it.isPlaying) it.seekTo(0) else it.start()
            }
        } catch (e: Exception) { }
    }

    /**
     * PTT key detection — "catch-all" approach.
     * Instead of whitelisting specific PTT keycodes, we BLOCK known system keys
     * and treat EVERYTHING ELSE as PTT. This works with any PTT device
     * (YTCOM ET200, RugGear, Motorola, Sonim, etc.)
     */
    fun isPTTKey(keyCode: Int): Boolean {
        // These are system keys we should NOT treat as PTT
        val systemKeys = setOf(
            KeyEvent.KEYCODE_BACK,
            KeyEvent.KEYCODE_HOME,
            KeyEvent.KEYCODE_MENU,
            KeyEvent.KEYCODE_APP_SWITCH,      // Recent apps
            KeyEvent.KEYCODE_POWER,
            KeyEvent.KEYCODE_VOLUME_UP,
            KeyEvent.KEYCODE_VOLUME_DOWN,
            KeyEvent.KEYCODE_VOLUME_MUTE,
            KeyEvent.KEYCODE_NAVIGATE_PREVIOUS,
            KeyEvent.KEYCODE_NAVIGATE_NEXT,
            KeyEvent.KEYCODE_NAVIGATE_IN,
            KeyEvent.KEYCODE_NAVIGATE_OUT,
            KeyEvent.KEYCODE_DPAD_UP,
            KeyEvent.KEYCODE_DPAD_DOWN,
            KeyEvent.KEYCODE_DPAD_LEFT,
            KeyEvent.KEYCODE_DPAD_RIGHT,
            KeyEvent.KEYCODE_DPAD_CENTER,
            KeyEvent.KEYCODE_ENTER,
            KeyEvent.KEYCODE_TAB,
            KeyEvent.KEYCODE_SPACE,
            KeyEvent.KEYCODE_DEL,             // Backspace
            KeyEvent.KEYCODE_FORWARD_DEL,
            KeyEvent.KEYCODE_ESCAPE,
            KeyEvent.KEYCODE_SEARCH,
            KeyEvent.KEYCODE_NOTIFICATION,
            KeyEvent.KEYCODE_BRIGHTNESS_UP,
            KeyEvent.KEYCODE_BRIGHTNESS_DOWN
        )
        // Any key that is NOT a system key = PTT
        return keyCode !in systemKeys && keyCode != 0
    }

    // Debug callback for key events (optional, for testing new devices)
    var onKeyDebug: ((Int, String) -> Unit)? = null

    fun release() {
        disconnect()
        audioTrack?.stop()
        audioTrack?.release()
        beepOn?.release()
        beepOff?.release()
        scope.cancel()
    }
}
