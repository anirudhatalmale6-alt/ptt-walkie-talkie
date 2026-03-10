package com.pttwalkie

import android.Manifest
import android.content.pm.PackageManager
import android.media.AudioFormat
import android.media.AudioManager
import android.media.AudioRecord
import android.media.AudioTrack
import android.media.MediaRecorder
import android.net.wifi.WifiManager
import android.os.Bundle
import android.os.PowerManager
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
import java.net.DatagramPacket
import java.net.DatagramSocket
import java.net.InetAddress
import java.net.MulticastSocket
import java.net.NetworkInterface

class MainActivity : AppCompatActivity() {

    companion object {
        private const val TAG = "PTTWalkie"
        private const val SAMPLE_RATE = 8000
        private const val CHANNEL_IN = AudioFormat.CHANNEL_IN_MONO
        private const val CHANNEL_OUT = AudioFormat.CHANNEL_OUT_MONO
        private const val ENCODING = AudioFormat.ENCODING_PCM_16BIT
        private const val PERMISSION_REQUEST = 100
        private const val MULTICAST_BASE = "239.255.0."  // + group number (1-254)
        private const val MULTICAST_PORT = 5060
        private const val CHUNK_SIZE = 1600  // 100ms of 8kHz 16-bit mono
    }

    private lateinit var etGroupNumber: EditText
    private lateinit var btnConnect: Button
    private lateinit var btnPTT: Button
    private lateinit var tvStatus: TextView
    private lateinit var tvMyIP: TextView
    private lateinit var tvHint: TextView

    private var isConnected = false
    private var isTransmitting = false

    private var multicastSocket: MulticastSocket? = null
    private var multicastGroup: InetAddress? = null
    private var multicastLock: WifiManager.MulticastLock? = null

    private var audioRecord: AudioRecord? = null
    private var audioTrack: AudioTrack? = null
    private var recordJob: Job? = null
    private var listenJob: Job? = null
    private var wakeLock: PowerManager.WakeLock? = null

    private val scope = CoroutineScope(Dispatchers.Main + SupervisorJob())

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        etGroupNumber = findViewById(R.id.etGroupNumber)
        btnConnect = findViewById(R.id.btnConnect)
        btnPTT = findViewById(R.id.btnPTT)
        tvStatus = findViewById(R.id.tvStatus)
        tvMyIP = findViewById(R.id.tvMyIP)
        tvHint = findViewById(R.id.tvHint)

        val pm = getSystemService(POWER_SERVICE) as PowerManager
        wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "pttwalkie:transmit")

        // Acquire multicast lock so WiFi chip receives multicast packets
        val wifiManager = applicationContext.getSystemService(WIFI_SERVICE) as WifiManager
        multicastLock = wifiManager.createMulticastLock("pttwalkie")

        checkPermissions()
        setupUI()
        showMyIP()
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

    private fun showMyIP() {
        try {
            val wifiManager = applicationContext.getSystemService(WIFI_SERVICE) as WifiManager
            val wifiInfo = wifiManager.connectionInfo
            val ip = wifiInfo.ipAddress
            val ipStr = String.format(
                "%d.%d.%d.%d",
                ip and 0xff, ip shr 8 and 0xff,
                ip shr 16 and 0xff, ip shr 24 and 0xff
            )
            tvMyIP.text = "ה-IP שלי: $ipStr"
        } catch (e: Exception) {
            tvMyIP.text = "ה-IP שלי: לא ידוע"
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
               keyCode == 1015 ||
               keyCode == 1024 ||
               keyCode == 261 ||
               keyCode == 286
    }

    private fun connect() {
        val groupStr = etGroupNumber.text.toString().trim()
        if (groupStr.isEmpty()) {
            Toast.makeText(this, "הכנס מספר קבוצה", Toast.LENGTH_SHORT).show()
            return
        }

        val groupNum = groupStr.toIntOrNull()
        if (groupNum == null || groupNum < 1 || groupNum > 254) {
            Toast.makeText(this, "מספר קבוצה חייב להיות 1-254", Toast.LENGTH_SHORT).show()
            return
        }

        tvStatus.text = "מתחבר..."
        tvStatus.setTextColor(0xFFFFAB00.toInt())
        btnConnect.isEnabled = false

        scope.launch(Dispatchers.IO) {
            try {
                // Enable multicast reception on WiFi
                multicastLock?.acquire()

                // Create multicast socket
                multicastSocket = MulticastSocket(MULTICAST_PORT)
                multicastSocket?.reuseAddress = true
                multicastSocket?.soTimeout = 0

                // Join multicast group based on group number
                val address = MULTICAST_BASE + groupNum
                multicastGroup = InetAddress.getByName(address)
                multicastSocket?.joinGroup(multicastGroup)

                // Init audio playback
                initAudioTrack()

                // Start listening for incoming audio
                startListening()

                withContext(Dispatchers.Main) {
                    isConnected = true
                    tvStatus.text = "מחובר ✓ קבוצה $groupNum ($address)"
                    tvStatus.setTextColor(0xFF4CAF50.toInt())
                    btnConnect.text = "התנתק"
                    btnConnect.isEnabled = true
                    btnConnect.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFFC62828.toInt())
                    btnPTT.isEnabled = true
                    btnPTT.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF2E7D32.toInt())
                }
            } catch (e: Exception) {
                Log.e(TAG, "Connect error", e)
                withContext(Dispatchers.Main) {
                    tvStatus.text = "שגיאה: ${e.message}"
                    tvStatus.setTextColor(0xFFFF5252.toInt())
                    btnConnect.isEnabled = true
                }
            }
        }
    }

    private fun disconnect() {
        stopTransmit()
        listenJob?.cancel()
        listenJob = null

        scope.launch(Dispatchers.IO) {
            try {
                multicastGroup?.let { multicastSocket?.leaveGroup(it) }
                multicastSocket?.close()
            } catch (e: Exception) {
                Log.e(TAG, "Disconnect error", e)
            }
            multicastSocket = null
            multicastGroup = null

            if (multicastLock?.isHeld == true) {
                multicastLock?.release()
            }

            audioTrack?.stop()
            audioTrack?.release()
            audioTrack = null

            withContext(Dispatchers.Main) {
                isConnected = false
                tvStatus.text = "לא מחובר"
                tvStatus.setTextColor(0xFFFF5252.toInt())
                resetUI()
            }
        }
    }

    private fun resetUI() {
        btnConnect.text = "התחבר"
        btnConnect.isEnabled = true
        btnConnect.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF2E7D32.toInt())
        btnPTT.isEnabled = false
        btnPTT.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF444466.toInt())
        btnPTT.text = "🎤\nלחץ לשידור"
        tvHint.text = "לחץ על כפתור PTT במכשיר או על הכפתור למעלה"
        tvHint.setTextColor(0xFF666666.toInt())
    }

    private fun startListening() {
        listenJob = scope.launch(Dispatchers.IO) {
            val buffer = ByteArray(CHUNK_SIZE + 4) // +4 for header
            val packet = DatagramPacket(buffer, buffer.size)

            while (isActive) {
                try {
                    multicastSocket?.receive(packet)

                    if (packet.length >= 4) {
                        val data = packet.data

                        // Check header: 'P' 'T' 'T' + type byte
                        if (data[0] == 'P'.code.toByte() && data[1] == 'T'.code.toByte() && data[2] == 'T'.code.toByte()) {
                            val type = data[3]

                            // Ignore our own packets (check source IP)
                            val senderIP = packet.address.hostAddress
                            val myIP = getMyIP()
                            if (senderIP == myIP) continue

                            when (type.toInt()) {
                                1 -> {
                                    // Audio data
                                    val audioData = data.copyOfRange(4, packet.length)
                                    playAudio(audioData)
                                }
                                2 -> {
                                    // TX start
                                    withContext(Dispatchers.Main) {
                                        tvHint.text = "📢 מישהו משדר... ($senderIP)"
                                        tvHint.setTextColor(0xFFFF9800.toInt())
                                    }
                                }
                                3 -> {
                                    // TX stop
                                    withContext(Dispatchers.Main) {
                                        tvHint.text = "לחץ על כפתור PTT במכשיר או על הכפתור למעלה"
                                        tvHint.setTextColor(0xFF666666.toInt())
                                    }
                                }
                            }
                        }
                    }
                } catch (e: Exception) {
                    if (isActive) {
                        Log.e(TAG, "Listen error", e)
                    }
                }
            }
        }
    }

    private fun getMyIP(): String {
        return try {
            val wifiManager = applicationContext.getSystemService(WIFI_SERVICE) as WifiManager
            val ip = wifiManager.connectionInfo.ipAddress
            String.format("%d.%d.%d.%d", ip and 0xff, ip shr 8 and 0xff, ip shr 16 and 0xff, ip shr 24 and 0xff)
        } catch (e: Exception) {
            ""
        }
    }

    private fun startTransmit() {
        if (!isConnected || isTransmitting) return
        isTransmitting = true

        wakeLock?.acquire(60000)

        btnPTT.text = "🔴\nמשדר..."
        btnPTT.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFFC62828.toInt())

        // Send TX start notification
        sendControlPacket(2)

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
                        sendAudioPacket(buffer, read)
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

        // Send TX stop notification
        sendControlPacket(3)
    }

    private fun sendAudioPacket(audio: ByteArray, length: Int) {
        try {
            // Header: P T T 0x01 + audio data
            val packet = ByteArray(4 + length)
            packet[0] = 'P'.code.toByte()
            packet[1] = 'T'.code.toByte()
            packet[2] = 'T'.code.toByte()
            packet[3] = 1  // type: audio

            System.arraycopy(audio, 0, packet, 4, length)

            val dgram = DatagramPacket(packet, packet.size, multicastGroup, MULTICAST_PORT)
            multicastSocket?.send(dgram)
        } catch (e: Exception) {
            Log.e(TAG, "Send audio error", e)
        }
    }

    private fun sendControlPacket(type: Int) {
        scope.launch(Dispatchers.IO) {
            try {
                val packet = byteArrayOf(
                    'P'.code.toByte(),
                    'T'.code.toByte(),
                    'T'.code.toByte(),
                    type.toByte()
                )
                val dgram = DatagramPacket(packet, packet.size, multicastGroup, MULTICAST_PORT)
                multicastSocket?.send(dgram)
            } catch (e: Exception) {
                Log.e(TAG, "Send control error", e)
            }
        }
    }

    private fun initAudioTrack() {
        val bufSize = AudioTrack.getMinBufferSize(SAMPLE_RATE, CHANNEL_OUT, ENCODING)
        audioTrack = AudioTrack(
            AudioManager.STREAM_MUSIC,
            SAMPLE_RATE,
            CHANNEL_OUT,
            ENCODING,
            maxOf(bufSize, CHUNK_SIZE * 2),
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
        scope.cancel()
        if (wakeLock?.isHeld == true) {
            wakeLock?.release()
        }
    }
}
