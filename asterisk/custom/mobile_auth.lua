--[[ Авторизация по входящему звонку ]]

function normalizeMobile(number)
    if number == nil then return "" end
    local digits = tostring(number):gsub("%D", "")
    if digits == "" then return "" end
    if digits:len() == 11 and digits:sub(1, 1) == "8" then
        digits = "7" .. digits:sub(2)
    elseif digits:len() == 10 then
        digits = "7" .. digits
    end
    return digits
end

function isAuthTrunkCall()
    if channel == nil then return false end
    local ch = channel.name():get() or ""
    return ch:find("auth_call") ~= nil
end

function handleAuthIncomingCall(extension)
    local extDigits = tostring(extension):gsub("%D", "")
    local from = channel.CALLERID("num"):get()
    local authTrunk = isAuthTrunkCall()

    logDebug("auth check ext=" .. extDigits .. " caller=" .. tostring(from) .. " trunk=" .. tostring(authTrunk))

    local authNumbers = {
        ["4983029903"] = true,
        ["74983029903"] = true,
        ["84983029903"] = true,
    }

    if not authTrunk and not authNumbers[extDigits] then
        return false
    end

    local mobile = normalizeMobile(from)
    if mobile == "" or mobile:len() ~= 11 then
        logDebug("auth call ignored, bad caller id: " .. tostring(from))
        app.Hangup()
        return true
    end

    redis:setex("isdn_incoming_+" .. mobile, 600, "1")
    redis:setex("isdn_incoming_" .. mobile, 600, "1")
    redis:setex("isdn_incoming_8" .. mobile:sub(2), 600, "1")

    logDebug("auth call stored for: " .. mobile)
    app.Hangup()
    return true
end

local _handleSIPIntercom = handleSIPIntercom
function handleSIPIntercom(context, extension)
    if handleAuthIncomingCall(extension) then return end
    return _handleSIPIntercom(context, extension)
end

local _handleOtherCases = handleOtherCases
function handleOtherCases(context, extension)
    if handleAuthIncomingCall(extension) then return end
    return _handleOtherCases(context, extension)
end

extensions["default"]["_4XXXXXXXXX"] = handleSIPIntercom
extensions["default"]["_X!"] = handleOtherCases
