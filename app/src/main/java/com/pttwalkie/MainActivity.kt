package com.pttwalkie

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.view.KeyEvent
import android.view.MotionEvent
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat

class MainActivity : AppCompatActivity() {

    companion object {
        private const val PERMISSION_REQUEST = 100
    }

    private lateinit var etGroupNumber: EditText
    private lateinit var btnConnect: Button
    private lateinit var btnPTT: Button
    private lateinit var tvStatus: TextView
    private lateinit var tvOnline: TextView
    private lateinit var tvHint: TextView
    private lateinit var tvTransmitting: TextView

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

        PTTEngine.init(applicationContext)
        checkPermissions()
        setupUI()
        setupEngineCallbacks()

        // Debug: show broadcast actions on screen
        PTTEngine.onBroadcastDebug = { action ->
            runOnUiThread {
                tvHint.text = "Broadcast: $action"
                tvHint.setTextColor(0xFF2196F3.toInt())
            }
        }
    }

    override fun onResume() {
        super.onResume()
        setupEngineCallbacks()
        if (PTTEngine.isConnected) {
            tvStatus.text = "מחובר ✓  קבוצה ${PTTEngine.currentGroup}"
            tvStatus.setTextColor(0xFF4CAF50.toInt())
            btnConnect.text = "התנתק"
            btnConnect.isEnabled = true
            btnConnect.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFFC62828.toInt())
            btnPTT.isEnabled = true
            btnPTT.backgroundTintList = android.content.res.ColorStateList.valueOf(
                if (PTTEngine.isTransmitting) 0xFFC62828.toInt() else 0xFF2E7D32.toInt()
            )
            btnPTT.text = if (PTTEngine.isTransmitting) "🔴" else "🎤"
            etGroupNumber.isEnabled = false
        }
    }

    private fun checkPermissions() {
        val needed = mutableListOf<String>()
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            needed.add(Manifest.permission.RECORD_AUDIO)
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
                needed.add(Manifest.permission.POST_NOTIFICATIONS)
            }
        }
        if (needed.isNotEmpty()) {
            ActivityCompat.requestPermissions(this, needed.toTypedArray(), PERMISSION_REQUEST)
        }
    }

    private fun setupEngineCallbacks() {
        PTTEngine.onStatusChanged = { text, color ->
            runOnUiThread {
                tvStatus.text = text
                tvStatus.setTextColor(color)
            }
        }
        PTTEngine.onOnlineCount = { count ->
            runOnUiThread { tvOnline.text = "👥 מחוברים בקבוצה: $count" }
        }
        PTTEngine.onTxStartRemote = {
            runOnUiThread {
                tvTransmitting.text = "📢 מישהו משדר..."
                tvTransmitting.setTextColor(0xFFFF9800.toInt())
            }
        }
        PTTEngine.onTxStopRemote = {
            runOnUiThread { tvTransmitting.text = "" }
        }
        PTTEngine.onConnected = {
            runOnUiThread {
                btnConnect.text = "התנתק"
                btnConnect.isEnabled = true
                btnConnect.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFFC62828.toInt())
                btnPTT.isEnabled = true
                btnPTT.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF2E7D32.toInt())
                btnPTT.text = "🎤"
                etGroupNumber.isEnabled = false
                startPTTService()
            }
        }
        PTTEngine.onDisconnected = {
            runOnUiThread {
                resetUI()
                stopPTTService()
            }
        }
        PTTEngine.onTransmitChanged = { transmitting ->
            runOnUiThread {
                if (transmitting) {
                    btnPTT.text = "🔴"
                    btnPTT.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFFC62828.toInt())
                } else {
                    btnPTT.text = "🎤"
                    btnPTT.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF2E7D32.toInt())
                }
            }
        }
    }

    private fun setupUI() {
        btnConnect.setOnClickListener {
            if (PTTEngine.isConnected) {
                PTTEngine.disconnect()
            } else {
                val group = etGroupNumber.text.toString().trim()
                if (group.isEmpty()) {
                    Toast.makeText(this, "הכנס מספר קבוצה", Toast.LENGTH_SHORT).show()
                    return@setOnClickListener
                }
                btnConnect.isEnabled = false
                PTTEngine.connect(group)
            }
        }

        btnPTT.setOnTouchListener { _, event ->
            when (event.action) {
                MotionEvent.ACTION_DOWN -> {
                    PTTEngine.startTransmit()
                    true
                }
                MotionEvent.ACTION_UP, MotionEvent.ACTION_CANCEL -> {
                    PTTEngine.stopTransmit()
                    true
                }
                else -> false
            }
        }
    }

    override fun onKeyDown(keyCode: Int, event: KeyEvent?): Boolean {
        // Debug: show keycode on tvHint for testing new devices
        runOnUiThread {
            tvHint.text = "Key: $keyCode (${KeyEvent.keyCodeToString(keyCode)})"
        }
        if (isPTTKey(keyCode)) {
            PTTEngine.startTransmit()
            return true
        }
        return super.onKeyDown(keyCode, event)
    }

    override fun onKeyUp(keyCode: Int, event: KeyEvent?): Boolean {
        if (isPTTKey(keyCode)) {
            PTTEngine.stopTransmit()
            return true
        }
        return super.onKeyUp(keyCode, event)
    }

    private fun isPTTKey(keyCode: Int): Boolean = PTTEngine.isPTTKey(keyCode)

    private fun resetUI() {
        btnConnect.text = "התחבר"
        btnConnect.isEnabled = true
        btnConnect.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFFFFC107.toInt())
        btnPTT.isEnabled = false
        btnPTT.backgroundTintList = android.content.res.ColorStateList.valueOf(0xFF3C3C4E.toInt())
        btnPTT.text = "🎤"
        etGroupNumber.isEnabled = true
        tvOnline.text = ""
        tvTransmitting.text = ""
    }

    private fun startPTTService() {
        val intent = Intent(this, PTTService::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            startForegroundService(intent)
        } else {
            startService(intent)
        }
    }

    private fun stopPTTService() {
        stopService(Intent(this, PTTService::class.java))
    }
}
