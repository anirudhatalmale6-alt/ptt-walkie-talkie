package com.pttwalkie

import android.Manifest
import android.content.pm.PackageManager
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioRecord
import android.media.AudioTrack
import android.media.MediaRecorder
import android.os.Bundle
import android.os.PowerManager
import android.util.Base64
import android.util.Log
import android.view.KeyEvent
import android.view.MotionEvent
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import kotlinx.coroutines.*
import okhttp3.*
import org.json.JSONObject

class MainActivity : AppCompatActivity() {

    companion object {
        private const val TAG = "PTTWalkie"
        private const val SAMPLE_RATE = 8000
        private const val CHANNEL_IN = AudioFormat.CHANNEL_IN_MONO
        private const val CHANNEL_OUT = AudioFormat.CHANNEL_OUT_MONO
        private const val ENCODING = AudioFormat.ENCODING_PCM_16BIT
        private const val PERMISSION_REQUEST = 100
        private const val CHUNK_SIZE = 1600  // 100ms of 8kHz 16-bit mono

        // Built-in relay servers — HTTPS tunnel first (works on all mobile networks)
        private val RELAY_SERVERS = listOf(
            "wss://namely-celtic-retreat-bull.trycloudflare.com",
            "ws://167.235.196.123:3000",
            "ws://167.235.196.123:4000",
            "ws://167.235.196.123:9000"
        )
    }

    private lateinit var etGroupNumber: EditText
    private lateinit var btnConnect: Button
    private lateinit var btnPTT: Button
    private lateinit var tvStatus: TextView
    private lateinit var tvOnline: TextView
    private lateinit var tvHint: TextView
    private lateinit var tvTransmitting: TextView

    private var webSocket: WebSocket? = null
    private var isConnected = false
    private var isTransmitting = false
    private var audioRecord: AudioRecord? = null
    private var audioTrack: AudioTrack? = null
    private var recordJob: Job? = null
    private var wakeLock: PowerManager.WakeLock? = null
    private var currentGroup = ""
    private var serverIndex = 0

    private val scope = CoroutineScope(Dispatchers.Main + SupervisorJob())
    private val client = OkHttpClient.Builder()
        .pingInterval(java.time.Duration.ofSeconds(15))
        .build()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        etGroupNumber = findViewById(R.id.etGroupNumber)
        btnConnect = findViewById(R.id.btnConnect)
        btnPTT = findViewById(R.id.btnPTT)
        tvStatus = findViewById(R.id.tvStatus)
        tvOnline = findViewById(R.id.tvOnline)
        tvHint = findViewById(R.id.tvHint)
        tvTransmitting = findViewById(R.id.tvTransmitting)

        val pm = getSystemService(POWER_SERVICE) as PowerManager
        wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "pttwalkie:transmit")

        checkPermissions()
        setupUI()
        initAudioTrack()
    }

    private fun checkPermissions() {
        val needed = mutableListOf<String>()
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            needed.add(Manifest.permission.RECORD_AUDIO)
        }
        if (needed.isNotEmpty()) {
            ActivityCompat.requestPermissions(this, needed.toTypedArray(), PERMISSION_REQUEST)
        }
    }

    private fun setupUI() {
        btnConnect.setOnClickListener {
            if (isConnected) {
                disconnect()
            } else {
                connect()
            }
        }

        // Big on-screen PTT button — press and hold to talk
        btnPTT.setOnTouchListener { v, event ->
            when (event.action) {
                MotionEvent.ACTION_DOWN -> {
                    startTransmit()
                    true
                }
                MotionEvent.ACTION_UP, MotionEvent.ACTION_CANCEL -> {
                    stopTransmit()
                    true
                }
                else -> false
            }
        }
    }

    // Hardware PTT key support (Motorola and other PTT devices)
    override fun onKeyDown(keyCode: Int, event: KeyEvent?): Boolean {
        if (isPTTKey(keyCode)) {
            startTransmit()
            return true
        }
        return super.onKeyDown(keyCode, event)
    }

    override fun onKeyUp(keyCode: Int, event: KeyEvent?): Boolean {
        if (isPTTKey(keyCode)) {
            stopTransmit()
            return true
        }
        return super.onKeyUp(keyCode, event)
    }

    private fun isPTTKey(keyCode: Int): Boolean {
        return keyCode == KeyEvent.KEYCODE_HEADSETHOOK ||
               keyCode == KeyEvent.KEYCODE_MEDIA_PLAY_PAUSE ||
               keyCode == KeyEvent.KEYCODE_CALL ||
               keyCode == KeyEvent.KEYCODE_MEDIA_RECORD ||
               keyCode == 1015 ||   // Motorola PTT
               keyCode == 1024 ||   // Some Motorola devices
               keyCode == 261 ||    // KEYCODE_PTT
               keyCode == 286
    }

    private fun connect() {
        val group = etGroupNumber.text.toString().trim()
        if (group.isEmpty()) {
            Toast.makeText(this, "הכנס מספר קבוצה", Toast.LENGTH_SHORT).show()
            return
        }

        currentGroup = group
        serverIndex = 0
        tryConnect(group)
    }

    private fun tryConnect(group: String) {
        if (serverIndex >= RELAY_SERVERS.size) {
            tvStatus.text = "לא הצליח להתחבר לאף שרת"
            tvStatus.setTextColor(0xFFFF5252.toInt())
            resetUI()
            return
        }

        val serverUrl = RELAY_SERVERS[serverIndex]
        tvStatus.text = "מתחבר... (ניסיון ${serverIndex + 1}/${RELAY_SERVERS.size})"
        tvStatus.setTextColor(0xFFFFAB00.toInt())
        btnConnect.isEnabled = false

        val url = "$serverUrl?group=$group"
        Log.d(TAG, "Trying server: $url")
        val request = Request.Builder().url(url).build()

        webSocket = client.newWebSocket(request, object : WebSocketListener() {
            override fun onOpen(webSocket: WebSocket, response: Response) {
                runOnUiThread {
                    isConnected = true
                    tvStatus.text = "מחובר ✓  קבוצה $currentGroup"
                    tvStatus.setTextColor(0xFF4CAF50.toInt())
                    btnConnect.text = "התנתק"
                    btnConnect.isEnabled = true
                    btnConnect.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFFC62828.toInt())
                    btnPTT.isEnabled = true
                    btnPTT.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF2E7D32.toInt())
                    etGroupNumber.isEnabled = false
                }
            }

            override fun onMessage(webSocket: WebSocket, text: String) {
                try {
                    val json = JSONObject(text)
                    when (json.optString("type")) {
                        "audio" -> {
                            val audioData = Base64.decode(json.getString("data"), Base64.NO_WRAP)
                            playAudio(audioData)
                        }
                        "count" -> {
                            val count = json.getInt("count")
                            runOnUiThread {
                                tvOnline.text = "👥 מחוברים בקבוצה: $count"
                            }
                        }
                        "tx_start" -> {
                            runOnUiThread {
                                tvTransmitting.text = "📢 מישהו משדר..."
                                tvTransmitting.setTextColor(0xFFFF9800.toInt())
                            }
                        }
                        "tx_stop" -> {
                            runOnUiThread {
                                tvTransmitting.text = ""
                            }
                        }
                    }
                } catch (e: Exception) {
                    Log.e(TAG, "Error parsing message", e)
                }
            }

            override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
                Log.e(TAG, "WebSocket failed on ${RELAY_SERVERS[serverIndex]}: ${t.message}", t)
                runOnUiThread {
                    // Try next server
                    serverIndex++
                    if (serverIndex < RELAY_SERVERS.size) {
                        tryConnect(currentGroup)
                    } else {
                        isConnected = false
                        tvStatus.text = "שגיאת חיבור: ${t.message}"
                        tvStatus.setTextColor(0xFFFF5252.toInt())
                        resetUI()
                    }
                }
            }

            override fun onClosed(webSocket: WebSocket, code: Int, reason: String) {
                runOnUiThread {
                    isConnected = false
                    tvStatus.text = "לא מחובר"
                    tvStatus.setTextColor(0xFFFF5252.toInt())
                    resetUI()
                }
            }
        })
    }

    private fun disconnect() {
        stopTransmit()
        webSocket?.close(1000, "user disconnect")
        webSocket = null
        isConnected = false
        tvStatus.text = "לא מחובר"
        tvStatus.setTextColor(0xFFFF5252.toInt())
        tvOnline.text = ""
        tvTransmitting.text = ""
        resetUI()
    }

    private fun resetUI() {
        btnConnect.text = "התחבר לקבוצה"
        btnConnect.isEnabled = true
        btnConnect.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF2E7D32.toInt())
        btnPTT.isEnabled = false
        btnPTT.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF444466.toInt())
        btnPTT.text = "🎤\n\nלחץ כאן לשידור\n\nPTT"
        etGroupNumber.isEnabled = true
    }

    private fun startTransmit() {
        if (!isConnected || isTransmitting) return
        isTransmitting = true

        wakeLock?.acquire(60000)

        btnPTT.text = "🔴\n\nמשדר...\n\nשחרר להפסיק"
        btnPTT.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFFC62828.toInt())

        webSocket?.send(JSONObject().put("type", "tx_start").toString())

        recordJob = scope.launch(Dispatchers.IO) {
            try {
                val bufSize = maxOf(
                    AudioRecord.getMinBufferSize(SAMPLE_RATE, CHANNEL_IN, ENCODING),
                    CHUNK_SIZE
                )

                audioRecord = AudioRecord(
                    MediaRecorder.AudioSource.MIC,
                    SAMPLE_RATE,
                    CHANNEL_IN,
                    ENCODING,
                    bufSize
                )

                if (audioRecord?.state != AudioRecord.STATE_INITIALIZED) {
                    Log.e(TAG, "AudioRecord failed to init")
                    return@launch
                }

                audioRecord?.startRecording()
                val buffer = ByteArray(CHUNK_SIZE)

                while (isActive && isTransmitting) {
                    val read = audioRecord?.read(buffer, 0, buffer.size) ?: -1
                    if (read > 0) {
                        val encoded = Base64.encodeToString(buffer, 0, read, Base64.NO_WRAP)
                        val json = JSONObject()
                            .put("type", "audio")
                            .put("data", encoded)
                        webSocket?.send(json.toString())
                    }
                }
            } catch (e: Exception) {
                Log.e(TAG, "Recording error", e)
            } finally {
                audioRecord?.stop()
                audioRecord?.release()
                audioRecord = null
            }
        }
    }

    private fun stopTransmit() {
        if (!isTransmitting) return
        isTransmitting = false

        recordJob?.cancel()
        recordJob = null

        if (wakeLock?.isHeld == true) {
            wakeLock?.release()
        }

        btnPTT.text = "🎤\n\nלחץ כאן לשידור\n\nPTT"
        btnPTT.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF2E7D32.toInt())

        webSocket?.send(JSONObject().put("type", "tx_stop").toString())
    }

    private fun initAudioTrack() {
        val bufSize = AudioTrack.getMinBufferSize(SAMPLE_RATE, CHANNEL_OUT, ENCODING)
        audioTrack = AudioTrack(
            AudioManager.STREAM_MUSIC,
            SAMPLE_RATE,
            CHANNEL_OUT,
            ENCODING,
            maxOf(bufSize, CHUNK_SIZE * 3),
            AudioTrack.MODE_STREAM
        )
        audioTrack?.play()
    }

    private fun playAudio(data: ByteArray) {
        try {
            audioTrack?.write(data, 0, data.size)
        } catch (e: Exception) {
            Log.e(TAG, "Playback error", e)
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        stopTransmit()
        disconnect()
        audioTrack?.stop()
        audioTrack?.release()
        scope.cancel()
        if (wakeLock?.isHeld == true) {
            wakeLock?.release()
        }
    }
}
