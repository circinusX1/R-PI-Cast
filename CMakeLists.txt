cmake_minimum_required(VERSION 3.19)
project(rpi-fastcast LANGUAGES CXX)



add_executable(rpi-fastcast
    main.cpp
    rpi-fastcast.service
    instal.sh
)


include(GNUInstallDirs)

install(TARGETS rpi-fastcast
    BUNDLE  DESTINATION .
    RUNTIME DESTINATION ${CMAKE_INSTALL_BINDIR}
    LIBRARY DESTINATION ${CMAKE_INSTALL_LIBDIR}
)
