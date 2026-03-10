package com.pttwalkie

import android.Manifest
import android.content.pm.PackageManager
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioRecord
import android.media.AudioTrack
import android.media.MediaRecorder
import android.os.Build
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
    }

    private lateinit var etServerUrl: EditText
    private lateinit var etGroupNumber: EditText
    private lateinit var btnConnect: Button
    private lateinit var btnPTT: Button
    private lateinit var tvStatus: TextView
    private lateinit var tvOnline: TextView
    private lateinit var tvHint: TextView

    private var webSocket: WebSocket? = null
    private var isConnected = false
    private var isTransmitting = false
    private var audioRecord: AudioRecord? = null
    private var audioTrack: AudioTrack? = null
    private var recordJob: Job? = null
    private var wakeLock: PowerManager.WakeLock? = null

    private val scope = CoroutineScope(Dispatchers.Main + SupervisorJob())
    private val client = OkHttpClient.Builder()
        .pingInterval(java.time.Duration.ofSeconds(15))
        .build()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        etServerUrl = findViewById(R.id.etServerUrl)
        etGroupNumber = findViewById(R.id.etGroupNumber)
        btnConnect = findViewById(R.id.btnConnect)
        btnPTT = findViewById(R.id.btnPTT)
        tvStatus = findViewById(R.id.tvStatus)
        tvOnline = findViewById(R.id.tvOnline)
        tvHint = findViewById(R.id.tvHint)

        // Wake lock to keep CPU alive during transmission
        val pm = getSystemService(POWER_SERVICE) as PowerManager
        wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "pttwalkie:transmit")

        checkPermissions()
        setupUI()
        initAudioTrack()
    }

    private fun checkPermissions() {
        val perms = mutableListOf(Manifest.permission.RECORD_AUDIO)
        val needed = perms.filter {
            ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
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

        // On-screen PTT button (touch-and-hold)
        btnPTT.setOnTouchListener { _, event ->
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

    // Capture hardware PTT key (Motorola uses various keycodes)
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
               keyCode == 1015 || // Motorola PTT specific
               keyCode == 1024 || // Some Motorola devices
               keyCode == 261 ||  // KEYCODE_PTT (API level undocumented on some devices)
               keyCode == 286     // Another common PTT keycode
    }

    private fun connect() {
        val serverUrl = etServerUrl.text.toString().trim()
        val group = etGroupNumber.text.toString().trim()

        if (serverUrl.isEmpty()) {
            Toast.makeText(this, "הכנס כתובת שרת", Toast.LENGTH_SHORT).show()
            return
        }
        if (group.isEmpty()) {
            Toast.makeText(this, "הכנס מספר קבוצה", Toast.LENGTH_SHORT).show()
            return
        }

        tvStatus.text = "מתחבר..."
        tvStatus.setTextColor(0xFFFFAB00.toInt())
        btnConnect.isEnabled = false

        val url = "$serverUrl?group=$group"
        val request = Request.Builder().url(url).build()

        webSocket = client.newWebSocket(request, object : WebSocketListener() {
            override fun onOpen(webSocket: WebSocket, response: Response) {
                runOnUiThread {
                    isConnected = true
                    tvStatus.text = "מחובר ✓ (קבוצה $group)"
                    tvStatus.setTextColor(0xFF4CAF50.toInt())
                    btnConnect.text = "התנתק"
                    btnConnect.isEnabled = true
                    btnConnect.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFFC62828.toInt())
                    btnPTT.isEnabled = true
                    btnPTT.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF2E7D32.toInt())
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
                                tvOnline.text = "מחוברים בקבוצה: $count"
                            }
                        }
                        "tx_start" -> {
                            runOnUiThread {
                                tvHint.text = "📢 מישהו משדר..."
                                tvHint.setTextColor(0xFFFF9800.toInt())
                            }
                        }
                        "tx_stop" -> {
                            runOnUiThread {
                                tvHint.text = "לחץ על כפתור PTT במכשיר או על הכפתור למעלה"
                                tvHint.setTextColor(0xFF666666.toInt())
                            }
                        }
                    }
                } catch (e: Exception) {
                    Log.e(TAG, "Error parsing message", e)
                }
            }

            override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
                Log.e(TAG, "WebSocket failed", t)
                runOnUiThread {
                    isConnected = false
                    tvStatus.text = "שגיאה: ${t.message}"
                    tvStatus.setTextColor(0xFFFF5252.toInt())
                    resetUI()
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
        resetUI()
    }

    private fun resetUI() {
        btnConnect.text = "התחבר"
        btnConnect.isEnabled = true
        btnConnect.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF2E7D32.toInt())
        btnPTT.isEnabled = false
        btnPTT.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF444466.toInt())
        btnPTT.text = "🎤\nלחץ לשידור"
    }

    private fun startTransmit() {
        if (!isConnected || isTransmitting) return
        isTransmitting = true

        wakeLock?.acquire(60000) // Max 60 seconds

        btnPTT.text = "🔴\nמשדר..."
        btnPTT.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFFC62828.toInt())

        // Notify others
        webSocket?.send(JSONObject().put("type", "tx_start").toString())

        // Start recording and streaming
        recordJob = scope.launch(Dispatchers.IO) {
            try {
                val bufSize = maxOf(
                    AudioRecord.getMinBufferSize(SAMPLE_RATE, CHANNEL_IN, ENCODING),
                    1600 // 100ms of 8kHz 16-bit mono
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
                val buffer = ByteArray(1600) // 100ms chunks

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

        btnPTT.text = "🎤\nלחץ לשידור"
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
            maxOf(bufSize, 3200),
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
