const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const cors = require('cors');
const path = require('path');
const messageRoutes = require('./routes/messages');
require('dotenv').config();

const app = express();
const server = http.createServer(app);
const io = socketIo(server, {
  cors: {
    origin: "*",
    methods: ["GET", "POST"]
  }
});

// Middleware
app.use(cors());
app.use(express.json());
app.use(express.static(path.join(__dirname, 'public')));

// Rutas
app.use('/api/messages', messageRoutes);

// Configuración de Socket.io para chat en tiempo real
io.on('connection', (socket) => {
  console.log('Usuario conectado:', socket.id);

  // Unirse a una sala de chat específica
  socket.on('join-chat', (chatId) => {
    socket.join(chatId);
    console.log(`Usuario ${socket.id} se unió al chat ${chatId}`);
  });

  // Manejar mensajes entrantes
  socket.on('send-message', (data) => {
    // Transmitir mensaje a todos en la sala excepto al remitente
    socket.to(data.chatId).emit('receive-message', {
      message: data.message,
      alias: data.alias,
      timestamp: new Date()
    });
  });

  socket.on('disconnect', () => {
    console.log('Usuario desconectado:', socket.id);
  });
});

// Ruta de prueba
app.get('/api/status', (req, res) => {
  res.json({ status: 'Servidor funcionando', timestamp: new Date() });
});

const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
  console.log(`Servidor ejecutándose en puerto ${PORT}`);
});