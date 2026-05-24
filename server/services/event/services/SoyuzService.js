import { SyslogService } from "./index.js";
import { API, mdTimer } from "../utils/index.js";

/**
 * Class representing a syslog event handler for Soyuz devices.
 * @augments SyslogService
 */
class SoyuzService extends SyslogService {
    constructor(unit, config, spamWords = []) {
        super(unit, config, spamWords);
        this.gateRabbits = {};

        /**
         * Object to store the timestamp of the last call done for each host.
         * @type {Object.<string, number>}
         */
        this.lastCallDone = {};

        /**
         * Threshold value, in seconds, between different call done messages.
         * @type {number}
         */
        this.callDoneThreshold = 2;
    }

    async handleSyslogMessage(date, host, msg) {
        // Start motion detection
        if (msg.includes("Motion detected")) {
            await API.motionDetection({ date: date, ip: host, motionActive: true });
            await mdTimer({ ip: host });
        }

        // Call to an apartment
        if (msg.includes("EVENT: Calling to ")) {
            const match = msg.match(/^EVENT: Calling to (\d+)(?: house (\d+))? flat/);
            if (match) {
                const house = match[2] === undefined ? 0 : match[1];
                const flat = house > 0 ? match[2] : match[1];

                (this.gateRabbits)[host] = {
                    ip: host,
                    prefix: parseInt(house),
                    apartmentNumber: parseInt(flat),
                };
            }
        }

        // Incoming DTMF for white rabbit: sending rabbit gate update
        if (msg.includes("EVENT: Opening door by DTMF")) {
            if ((this.gateRabbits)[host]) {
                const { ip, prefix, apartmentNumber } = this.gateRabbits[host];
                await API.setRabbitGates({ date: date, ip, prefix, apartmentNumber });
            }
        }

        // LCcam / OpenAPI: "EVENT: The door 0 was opened using a key ..."
        // LCcam: "The door 0 was opened using a key 00000075596D74 (1968794996)" — без префикса EVENT:
        const openedByKey = msg.match(
            /(?:EVENT: )?The door (\d+) was opened using a key ([A-Fa-f0-9]+)(?:\s+\(\d+\))?/,
        );
        if (openedByKey) {
            await API.openDoor({
                date: date,
                ip: host,
                door: parseInt(openedByKey[1], 10),
                detail: openedByKey[2],
                by: "rfid",
            });
        }

        // LCcam / OpenAPI: "EVENT: The door 0 was opened using a code ..."
        const openedByCode = msg.match(
            /(?:EVENT: )?The door (\d+) was opened using a code (\d+)(?:\s+\(\d+\))?/,
        );
        if (openedByCode) {
            await API.openDoor({
                date: date,
                ip: host,
                door: parseInt(openedByCode[1], 10),
                detail: openedByCode[2],
                by: "code",
            });
        }

        // Legacy / alternate firmware strings
        if (msg.includes("EVENT: Opening door by RFID")) {
            const match = msg.match(/^EVENT: Opening door by RFID ([A-Fa-f0-9]{14})/);
            if (match) {
                await API.openDoor({ date: date, ip: host, door: 0, detail: match[1], by: "rfid" });
            }
        }

        if (msg.includes("EVENT: Opening door by CODE")) {
            const match = msg.match(/^EVENT: Opening door by CODE (\d+)/);
            if (match) {
                await API.openDoor({ date: date, ip: host, door: 0, detail: match[1], by: "code" });
            }
        }

        if (msg.includes("EVENT: Opening door by BUTTON")) {
            await API.openDoor({ date: date, ip: host, door: 0, detail: "main", by: "button" });
        }

        // Opened from mobile app / API (LCcam journal)
        if (msg.includes("opened through Web") || msg.includes("opened via Web")) {
            await API.openDoor({ date: date, ip: host, door: 0, detail: "app", by: "api" });
        }

        // All calls are done
        if (msg.includes("EVENT: All calls are done") || msg.includes("EVENT: Handset call done")) {
            if (!this.lastCallDone[host] || date - this.lastCallDone[host] > this.callDoneThreshold) {
                this.lastCallDone[host] = date;
                await API.callFinished({ date: date, ip: host });
            }
        }
    }
}

export { SoyuzService };
