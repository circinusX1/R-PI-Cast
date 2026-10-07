#include <iostream>
#include <string>
#include <cstring>
#include <cstdlib>
#include <thread>
#include <vector>
#include <sstream>
#include <iomanip>
#include <fstream>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <unistd.h>
#include <ifaddrs.h>
#include <netdb.h>

/**
fun encryptCommand(command: String, secret: String): String {
    val commandBytes = command.toByteArray(Charsets.UTF_8)
    val secretBytes = secret.toByteArray(Charsets.UTF_8)
    val encryptedBytes = ByteArray(commandBytes.size)

    for (i in commandBytes.indices) {
        encryptedBytes[i] = (commandBytes[i].toInt() xor secretBytes[i % secretBytes.size].toInt()).toByte()
    }

    // Convert encrypted bytes to a Hex string matching the C++ hexToBytes expectations
    val hexString = StringBuilder()
    for (b in encryptedBytes) {
        hexString.append(String.format("%02x", b))
    }
    return hexString.toString()
}


mpv --ao=alsa --audio-device=alsa/plughw:vc4hdmi0,0 --hwdec=v4l2m2m-copy --vo=gpu --gpu-context=drm --fs --cache=yes --hls-bitrate=3100000 -- 'https://canale-tv.net/channel/cartoonnet.html'
mpv --ao=alsa --audio-device=alsa/plughw:Headphones,0 --hwdec=v4l2m2m-copy --vo=gpu --gpu-context=drm --fs --cache=yes --hls-bitrate=3100000 -- 'https://canale-tv.net/channel-v2/cartoonnet.html'

*/

// Simple XOR cipher combined with hex encoding/decoding for safe string transmission
std::string xorEncryptDecrypt(const std::string& input, const std::string& secret)
{
    std::string output = input;
    for (size_t i = 0; i < input.size(); ++i)
    {
        output[i] = input[i] ^ secret[i % secret.size()];
    }
    return output;
}

std::string bytesToHex(const std::string& input)
{
    std::ostringstream hexStream;
    for (unsigned char c : input)
    {
        hexStream << std::hex << std::setw(2) << std::setfill('0') << (int)c;
    }
    return hexStream.str();
}

std::string hexToBytes(const std::string& hex)
{
    std::string bytes;
    for (size_t i = 0; i < hex.length(); i += 2)
    {
        std::string byteString = hex.substr(i, 2);
        char byte = (char)strtol(byteString.c_str(), NULL, 16);
        bytes.push_back(byte);
    }
    return bytes;
}

// Function to retrieve the local network IP address dynamically
std::string getLocalIP()
{
    struct ifaddrs *ifaddr = nullptr;
    std::string localIp = "127.0.0.1";

    if (getifaddrs(&ifaddr) == -1)
    {
        return localIp;
    }

    for (struct ifaddrs *ifa = ifaddr; ifa != nullptr; ifa = ifa->ifa_next)
    {
        if (ifa->ifa_addr == nullptr) continue;
        int family = ifa->ifa_addr->sa_family;

        // Look for IPv4 addresses that are not loopback
        if (family == AF_INET)
        {
            char host[NI_MAXHOST];
            int s = getnameinfo(ifa->ifa_addr, sizeof(struct sockaddr_in),
                                host, NI_MAXHOST, nullptr, 0, NI_NUMERICHOST);
            if (s == 0)
            {
                std::string ip(host);
                if (ip != "127.0.0.1")
                {
                    localIp = ip;
                    break;
                }
            }
        }
    }
    freeifaddrs(ifaddr);
    return localIp;
}

// UDP Listener Thread: Responds with "X.Y.Z.K:TTT"
void udpServer(int port, int tcpPort)
{
    int sock = socket(AF_INET, SOCK_DGRAM, 0);
    if (sock < 0)
    {
        std::cerr << "[UDP] Failed to create socket\n";
        return;
    }

    // Allow address reuse
    int optval = 1;
    setsockopt(sock, SOL_SOCKET, SO_REUSEADDR, &optval, sizeof(optval));

    sockaddr_in serverAddr{};
    serverAddr.sin_family = AF_INET;
    serverAddr.sin_addr.s_addr = htonl(INADDR_ANY);
    serverAddr.sin_port = htons(port);

    if (bind(sock, (struct sockaddr*)&serverAddr, sizeof(serverAddr)) < 0)
    {
        std::cerr << "[UDP] Bind failed on port " << port << "\n";
        close(sock);
        return;
    }

    std::cout << "[UDP] Listening on port " << port << "...\n";

    char buffer[1024];
    while (true)
    {
        sockaddr_in clientAddr{};
        socklen_t clientLen = sizeof(clientAddr);
        memset(buffer, 0, sizeof(buffer));

        ssize_t recvLen = recvfrom(sock, buffer, sizeof(buffer) - 1, 0,
                                   (struct sockaddr*)&clientAddr, &clientLen);
        if (recvLen > 0)
        {
            std::string localIp = getLocalIP();
            std::string response = localIp + ":" + std::to_string(tcpPort);

            sendto(sock, response.c_str(), response.length(), 0,
                   (struct sockaddr*)&clientAddr, clientLen);
        }
    }
    close(sock);
}

// TCP Server Thread: Receives, decrypts, and executes commands
void tcpServer(int port, std::string secret)
{
    int serverSock = socket(AF_INET, SOCK_STREAM, 0);
    if (serverSock < 0)
    {
        std::cerr << "[TCP] Failed to create socket\n";
        return;
    }

    int optval = 1;
    setsockopt(serverSock, SOL_SOCKET, SO_REUSEADDR, &optval, sizeof(optval));

    sockaddr_in serverAddr{};
    serverAddr.sin_family = AF_INET;
    serverAddr.sin_addr.s_addr = htonl(INADDR_ANY);
    serverAddr.sin_port = htons(port);

    if (bind(serverSock, (struct sockaddr*)&serverAddr, sizeof(serverAddr)) < 0)
    {
        std::cerr << "[TCP] Bind failed on port " << port << "\n";
        close(serverSock);
        return;
    }

    if (listen(serverSock, 5) < 0)
    {
        std::cerr << "[TCP] Listen failed\n";
        close(serverSock);
        return;
    }

    std::cout << "[TCP] Listening on port " << port << "...\n";

    while (true)
    {
        sockaddr_in clientAddr{};
        socklen_t clientLen = sizeof(clientAddr);
        int clientSock = accept(serverSock, (struct sockaddr*)&clientAddr, &clientLen);
        if (clientSock < 0) continue;

        std::string encryptedHex = "";
        char buffer[1024];

        memset(buffer, 0, sizeof(buffer));
        ssize_t bytesReceived = recv(clientSock, buffer, sizeof(buffer) - 1, 0);
        encryptedHex.append(buffer, bytesReceived);
        send(clientSock, "OK", 2, 0);
        usleep(100000);
        close(clientSock);

        // Trim whitespace/newlines if any
        while (!encryptedHex.empty() && (encryptedHex.back() == '\n' || encryptedHex.back() == '\r'))
        {
            encryptedHex.pop_back();
        }

        if (!encryptedHex.empty())
        {
            try
            {
                std::string cipherBytes = hexToBytes(encryptedHex);
                std::string decryptedCommand = xorEncryptDecrypt(cipherBytes, secret);

                // Clear console screen and print the received string directly to /dev/tty0
                std::ofstream tty0("/dev/tty0");
                if (tty0.is_open())
                {
                    tty0 << "\033[2J\033[H \n\n\n\n\n\n" << decryptedCommand << "\n";
                    tty0.close();
                }

                std::cout <<  decryptedCommand << "\n";

                std::system("pkill mpv");
                std::system("pkill -9 mpv");
                usleep(1000000); // Pause to ensure DRM resources release

                if (decryptedCommand == "STOP")
                {
                    std::system("mpv --vo=gpu --gpu-context=drm --fs --no-audio image1.png &");
                }
                else if(decryptedCommand.find("mpv") !=  std::string::npos)
                {
                    decryptedCommand += " &";
                    int result = std::system(decryptedCommand.c_str());
                    (void)result;
                }
            }
            catch (const std::exception& e)
            {
                std::cerr << "[TCP] Error processing command: " << e.what() << "\n";
            }
        }
    }
    close(serverSock);
}

int main(int argc, char* argv[])
{
    int udpPort = 3333;
    int tcpPort = 3333;
    std::string secret = "1234";

    // Parse command line arguments: -U, -T, -P
    for (int i = 1; i < argc; ++i)
    {
        std::string arg = argv[i];
        if (arg.rfind("-U", 0) == 0)
        {
            udpPort = std::stoi(arg.substr(2));
        }
        else if (arg.rfind("-T", 0) == 0)
        {
            tcpPort = std::stoi(arg.substr(2));
        }
        else if (arg.rfind("-P", 0) == 0)
        {
            secret = arg.substr(2);
        }
    }

    std::cout << "Starting rpi-fastcast...\n";

    // Paint image1 on HDMI at startup using mpv with DRM acceleration
    std::system("mpv --vo=gpu --gpu-context=drm --fs --no-audio image1.png &");

    // Run UDP and TCP servers in background threads
    std::thread udpThread(udpServer, udpPort, tcpPort);
    std::thread tcpThread(tcpServer, tcpPort, secret);

    udpThread.join();
    tcpThread.join();

    return 0;
}
